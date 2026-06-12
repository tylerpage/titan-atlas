<?php

namespace App\Services\AI;

use App\Models\ClientDashboard;
use App\Models\DashboardAgentMemory;
use App\Models\User;
use Illuminate\Support\Str;

class DashboardAgentMemoryService
{
    public function enabled(): bool
    {
        return (bool) config('titan.agent_memory.enabled', true);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function remember(
        ClientDashboard $dashboard,
        array $payload,
        ?User $user = null,
    ): ?DashboardAgentMemory {
        if (! $this->enabled()) {
            return null;
        }

        $key = trim((string) ($payload['memory_key'] ?? ''));

        if ($key === '') {
            return null;
        }

        return DashboardAgentMemory::query()->updateOrCreate(
            [
                'client_dashboard_id' => $dashboard->id,
                'memory_key' => $key,
            ],
            [
                'category' => (string) ($payload['category'] ?? 'general'),
                'agent_flow' => (string) ($payload['agent_flow'] ?? 'both'),
                'title' => Str::limit(trim((string) ($payload['title'] ?? $key)), 120),
                'content' => trim((string) ($payload['content'] ?? '')),
                'source_tool' => isset($payload['source_tool']) ? (string) $payload['source_tool'] : null,
                'created_by' => $user?->id,
                'metadata' => $payload['metadata'] ?? null,
                'last_used_at' => now(),
            ],
        );
    }

    public function forPrompt(ClientDashboard $dashboard, ?string $flow = null): string
    {
        if (! $this->enabled()) {
            return '';
        }

        $limit = max(1, (int) config('titan.agent_memory.max_injected', 6));
        $maxChars = max(500, (int) config('titan.agent_memory.max_content_chars', 4000));

        $query = DashboardAgentMemory::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->when($flow !== null, function ($builder) use ($flow) {
                $builder->where(function ($inner) use ($flow) {
                    $inner->where('agent_flow', 'both')
                        ->orWhere('agent_flow', $flow);
                });
            })
            ->orderByDesc('last_used_at')
            ->orderByDesc('updated_at')
            ->limit($limit);

        $memories = $query->get();

        if ($memories->isEmpty()) {
            return '';
        }

        $lines = [];
        $usedChars = 0;

        foreach ($memories as $memory) {
            $line = sprintf(
                '- [%s] %s — %s',
                $memory->category,
                $memory->memory_key,
                Str::limit(str_replace("\n", ' ', $memory->content), 240),
            );

            if ($usedChars + strlen($line) > $maxChars) {
                break;
            }

            $lines[] = $line;
            $usedChars += strlen($line);
            $memory->update(['last_used_at' => now()]);
        }

        if ($lines === []) {
            return '';
        }

        return "## Dashboard memory (reuse before re-researching)\n"
            .implode("\n", $lines)
            ."\nWhen you confirm or update a fact, call SaveDashboardMemoryTool.";
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listForDashboard(ClientDashboard $dashboard, ?string $flow = null, int $limit = 25): array
    {
        return DashboardAgentMemory::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->when($flow !== null, fn ($q) => $q->where(function ($inner) use ($flow) {
                $inner->where('agent_flow', 'both')->orWhere('agent_flow', $flow);
            }))
            ->orderByDesc('updated_at')
            ->limit($limit)
            ->get()
            ->map(fn (DashboardAgentMemory $memory) => [
                'memory_key' => $memory->memory_key,
                'category' => $memory->category,
                'agent_flow' => $memory->agent_flow,
                'title' => $memory->title,
                'content' => $memory->content,
                'source_tool' => $memory->source_tool,
                'updated_at' => $memory->updated_at?->toDateTimeString(),
            ])
            ->all();
    }

    public function invalidateConnectorType(ClientDashboard $dashboard, string $connectorType): int
    {
        return DashboardAgentMemory::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->where(function ($query) use ($connectorType) {
                $query->where('memory_key', 'like', $connectorType.':%')
                    ->orWhere('metadata->connector_type', $connectorType);
            })
            ->delete();
    }

    public function rememberSuccessfulReport(
        ClientDashboard $dashboard,
        ?User $user,
        string $prompt,
        string $sql,
        string $visualizationType,
    ): void {
        $normalizedSql = preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
        $memoryKey = str_contains($normalizedSql, 'campaign_daily')
            ? 'google_ads:sql:campaign_performance'
            : 'sql:'.Str::slug(Str::limit($prompt, 40));

        $this->remember($dashboard, [
            'memory_key' => $memoryKey,
            'category' => 'sql_pattern',
            'agent_flow' => 'reporting',
            'title' => Str::limit($prompt, 80),
            'content' => "SQL ({$visualizationType}): {$normalizedSql}",
            'source_tool' => 'SaveAnalyticsReportTool',
            'metadata' => [
                'visualization_type' => $visualizationType,
            ],
        ], $user);
    }

    public function invalidateBlueprintSlug(ClientDashboard $dashboard, string $slug): int
    {
        return DashboardAgentMemory::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->where(function ($query) use ($slug) {
                $query->where('memory_key', 'like', $slug.':%')
                    ->orWhere('metadata->blueprint_slug', $slug);
            })
            ->delete();
    }
}
