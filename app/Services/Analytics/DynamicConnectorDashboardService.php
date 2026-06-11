<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\SavedDashboard;
use App\Services\Client\SavedDashboardService;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;

class DynamicConnectorDashboardService
{
    public function __construct(
        protected SavedDashboardService $savedDashboards,
        protected WidgetDataService $widgets,
        protected ConnectorBlueprintDashboardVersionService $layouts,
    ) {}

    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     * @return array<string, mixed>|null
     */
    public function dataFor(
        ClientDashboard $dashboard,
        Connection $connection,
        string $dateRange,
        ?array $customRange,
        DateComparison $comparison,
    ): ?array {
        if (! $connection->isDynamic()) {
            return null;
        }

        $connection->loadMissing('connectorBlueprint');
        $blueprint = $connection->connectorBlueprint;

        if ($blueprint === null) {
            return null;
        }

        $spec = $this->layouts->currentSpec($blueprint, $dashboard) ?? [];
        $savedDashboardId = $spec['saved_dashboard_id'] ?? null;

        if (! is_numeric($savedDashboardId)) {
            return null;
        }

        $board = SavedDashboard::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->find((int) $savedDashboardId);

        if ($board === null) {
            return null;
        }

        [$start, $end] = $this->widgets->resolveDateRange($dashboard, $dateRange, $customRange);
        $resolved = $this->savedDashboards->resolveBoard($board, $dashboard, $start, $end, $connection);

        return [
            'kind' => 'dynamic',
            'title' => (string) ($resolved['title'] ?? ($spec['title'] ?? $blueprint?->label ?? $connection->name)),
            'description' => $resolved['description'] ?? null,
            'blocks' => $resolved['blocks'],
            'saved_dashboard_id' => $board->id,
            'blueprint_label' => $blueprint?->label,
        ];
    }
}
