<?php

namespace App\Services\ConnectorBuilder;

use App\Models\ClientDashboard;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintDashboardVersion;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ConnectorBlueprintDashboardVersionService
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listForBlueprint(ConnectorBlueprint $blueprint, ClientDashboard $dashboard, int $limit = 10): array
    {
        return ConnectorBlueprintDashboardVersion::query()
            ->where('connector_blueprint_id', $blueprint->id)
            ->where('client_dashboard_id', $dashboard->id)
            ->orderByDesc('version_number')
            ->limit($limit)
            ->get(['id', 'version_number', 'created_at', 'created_by'])
            ->map(fn (ConnectorBlueprintDashboardVersion $version) => [
                'id' => $version->id,
                'version_number' => $version->version_number,
                'created_at' => $version->created_at?->toIso8601String(),
            ])
            ->all();
    }

    public function snapshot(
        ConnectorBlueprint $blueprint,
        ClientDashboard $dashboard,
        User $user,
    ): ?ConnectorBlueprintDashboardVersion {
        $this->assertSameDashboard($blueprint, $dashboard);

        $spec = $this->currentSpec($blueprint, $dashboard)
            ?? (is_array($blueprint->dashboard_spec) ? $blueprint->dashboard_spec : []);

        if ($spec === []) {
            return null;
        }

        $nextVersion = $this->nextVersionNumber($blueprint, $dashboard);

        return ConnectorBlueprintDashboardVersion::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'client_dashboard_id' => $dashboard->id,
            'version_number' => $nextVersion,
            'dashboard_spec' => $spec,
            'created_by' => $user->id,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentSpec(ConnectorBlueprint $blueprint, ClientDashboard $dashboard): ?array
    {
        $versions = ConnectorBlueprintDashboardVersion::query()
            ->where('connector_blueprint_id', $blueprint->id)
            ->where('client_dashboard_id', $dashboard->id)
            ->orderByDesc('version_number')
            ->get();

        foreach ($versions as $version) {
            $spec = is_array($version->dashboard_spec) ? $version->dashboard_spec : [];

            if (is_numeric($spec['saved_dashboard_id'] ?? null)) {
                return $spec;
            }
        }

        $spec = is_array($blueprint->dashboard_spec) ? $blueprint->dashboard_spec : [];

        if (! is_numeric($spec['saved_dashboard_id'] ?? null)) {
            return null;
        }

        $ownerId = $spec['client_dashboard_id'] ?? $blueprint->client_dashboard_id;

        if ($ownerId !== null && (int) $ownerId !== (int) $dashboard->id) {
            return null;
        }

        if (! $blueprint->isGlobal()
            && $blueprint->client_dashboard_id !== null
            && (int) $blueprint->client_dashboard_id !== (int) $dashboard->id) {
            return null;
        }

        return $spec;
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    public function recordCurrent(
        ConnectorBlueprint $blueprint,
        ClientDashboard $dashboard,
        User $user,
        array $spec,
    ): ConnectorBlueprintDashboardVersion {
        $this->assertSameDashboard($blueprint, $dashboard);

        return ConnectorBlueprintDashboardVersion::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'client_dashboard_id' => $dashboard->id,
            'version_number' => $this->nextVersionNumber($blueprint, $dashboard),
            'dashboard_spec' => $spec,
            'created_by' => $user->id,
        ]);
    }

    public function hasWidgetTemplate(ConnectorBlueprint $blueprint): bool
    {
        $widgets = $blueprint->dashboard_spec['widgets'] ?? [];

        return is_array($widgets) && $widgets !== [];
    }

    public function revert(
        ConnectorBlueprint $blueprint,
        ClientDashboard $dashboard,
        ConnectorBlueprintDashboardVersion $version,
    ): ConnectorBlueprint {
        $this->assertSameDashboard($blueprint, $dashboard);

        if ($version->connector_blueprint_id !== $blueprint->id
            || $version->client_dashboard_id !== $dashboard->id) {
            throw ValidationException::withMessages([
                'version' => 'That dashboard version does not belong to this blueprint and dashboard.',
            ]);
        }

        return DB::transaction(function () use ($blueprint, $dashboard, $version) {
            $spec = $version->dashboard_spec ?? [];

            $blueprint->update(['dashboard_spec' => $spec]);
            $this->restoreSavedDashboardLayout($dashboard, $spec);

            return $blueprint->fresh();
        });
    }

    /**
     * @param  array<string, mixed>  $spec
     */
    protected function restoreSavedDashboardLayout(ClientDashboard $dashboard, array $spec): void
    {
        $savedDashboardId = $spec['saved_dashboard_id'] ?? null;

        if (! is_numeric($savedDashboardId)) {
            return;
        }

        $board = SavedDashboard::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->find((int) $savedDashboardId);

        if ($board === null) {
            return;
        }

        SavedDashboardBlock::query()->where('saved_dashboard_id', $board->id)->delete();

        $pinnedBlocks = $spec['pinned_blocks'] ?? [];
        $sortOrder = 1;

        if (is_array($pinnedBlocks) && $pinnedBlocks !== []) {
            foreach ($pinnedBlocks as $block) {
                if (! is_array($block) || ! isset($block['report_id'])) {
                    continue;
                }

                SavedDashboardBlock::query()->create([
                    'saved_dashboard_id' => $board->id,
                    'analytics_report_id' => (int) $block['report_id'],
                    'title' => is_string($block['title'] ?? null) ? $block['title'] : null,
                    'column_span' => max(1, min(2, (int) ($block['column_span'] ?? 1))),
                    'sort_order' => $sortOrder++,
                ]);
            }

            return;
        }

        foreach ($spec['created_report_ids'] ?? [] as $reportId) {
            if (! is_numeric($reportId)) {
                continue;
            }

            SavedDashboardBlock::query()->create([
                'saved_dashboard_id' => $board->id,
                'analytics_report_id' => (int) $reportId,
                'column_span' => 1,
                'sort_order' => $sortOrder++,
            ]);
        }
    }

    protected function assertSameDashboard(ConnectorBlueprint $blueprint, ClientDashboard $dashboard): void
    {
        if ($blueprint->isGlobal() || $blueprint->isShared()) {
            return;
        }

        if ($blueprint->client_dashboard_id !== null && $blueprint->client_dashboard_id !== $dashboard->id) {
            throw ValidationException::withMessages([
                'dashboard' => 'Share this AI connector template company-wide before building dashboards on other client dashboards.',
            ]);
        }
    }

    protected function nextVersionNumber(ConnectorBlueprint $blueprint, ClientDashboard $dashboard): int
    {
        return ((int) ConnectorBlueprintDashboardVersion::query()
            ->where('connector_blueprint_id', $blueprint->id)
            ->where('client_dashboard_id', $dashboard->id)
            ->max('version_number')) + 1;
    }
}
