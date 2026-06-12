<?php

namespace App\Console\Commands;

use App\Models\AiAgentTrace;
use App\Models\AnalyticsReportSession;
use Illuminate\Console\Command;

class AiStatsCommand extends Command
{
    protected $signature = 'titan:ai-stats
                            {--days=7 : Look back this many days}
                            {--dashboard= : Filter to a client dashboard ID}';

    protected $description = 'Summarize TitanAI session durations, traces, and fast-path usage';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('days'));
        $since = now()->subDays($days);

        $query = AnalyticsReportSession::query()
            ->where('updated_at', '>=', $since)
            ->whereNotNull('duration_ms');

        if ($dashboardId = $this->option('dashboard')) {
            $query->where('client_dashboard_id', (int) $dashboardId);
        }

        $sessions = $query->get();

        if ($sessions->isEmpty()) {
            $this->warn("No timed AI sessions in the last {$days} day(s).");
        } else {
            $durations = $sessions->pluck('duration_ms');
            $fastPathCount = $sessions->where('used_fast_path', true)->count();

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Sessions with timing', (string) $sessions->count()],
                    ['Fast-path sessions', (string) $fastPathCount],
                    ['Median duration (ms)', (string) (int) $durations->median()],
                    ['P95 duration (ms)', (string) $this->percentile($durations->all(), 95)],
                    ['Max duration (ms)', (string) $durations->max()],
                ],
            );

            $recent = AnalyticsReportSession::query()
                ->where('updated_at', '>=', $since)
                ->whereNotNull('duration_ms')
                ->when($dashboardId, fn ($q) => $q->where('client_dashboard_id', (int) $dashboardId))
                ->orderByDesc('updated_at')
                ->limit(10)
                ->get(['id', 'client_dashboard_id', 'duration_ms', 'used_fast_path', 'status', 'updated_at']);

            if ($recent->isNotEmpty()) {
                $this->line('Recent sessions:');
                $this->table(
                    ['ID', 'Dashboard', 'Duration (ms)', 'Fast path', 'Status', 'Updated'],
                    $recent->map(fn (AnalyticsReportSession $session) => [
                        $session->id,
                        $session->client_dashboard_id,
                        $session->duration_ms,
                        $session->used_fast_path ? 'yes' : 'no',
                        $session->status->value,
                        $session->updated_at?->toDateTimeString(),
                    ])->all(),
                );
            }
        }

        $this->renderTraceStats($since);

        return self::SUCCESS;
    }

    protected function renderTraceStats(\Illuminate\Support\Carbon $since): void
    {
        $traces = AiAgentTrace::query()
            ->where('created_at', '>=', $since)
            ->get();

        if ($traces->isEmpty()) {
            $this->newLine();
            $this->warn('No AI agent traces recorded in this period. Enable TITAN_AI_PERF_LOGGING and run a chat session.');

            return;
        }

        $this->newLine();
        $this->info('AI agent traces');

        $toolMs = $traces->pluck('tool_ms');
        $llmMs = $traces->pluck('estimated_llm_ms');
        $queueMs = $traces->whereNotNull('queue_wait_ms')->pluck('queue_wait_ms');

        $this->table(
            ['Metric', 'Value'],
            [
                ['Traces recorded', (string) $traces->count()],
                ['Median tool time (ms)', (string) (int) $toolMs->median()],
                ['Median est. LLM time (ms)', (string) (int) $llmMs->median()],
                ['P95 est. LLM time (ms)', (string) $this->percentile($llmMs->all(), 95)],
                ['Median queue wait (ms)', $queueMs->isNotEmpty() ? (string) (int) $queueMs->median() : 'n/a'],
                ['Near max steps', (string) $traces->filter(fn (AiAgentTrace $trace) => $trace->max_steps !== null
                    && $trace->steps_count >= ($trace->max_steps - 1))->count()],
            ],
        );

        $toolTotals = [];

        foreach ($traces as $trace) {
            foreach ($trace->tools_json ?? [] as $tool) {
                $name = (string) ($tool['name'] ?? 'unknown');
                $toolTotals[$name]['count'] = ($toolTotals[$name]['count'] ?? 0) + 1;
                $toolTotals[$name]['total_ms'] = ($toolTotals[$name]['total_ms'] ?? 0) + (int) ($tool['duration_ms'] ?? 0);
            }
        }

        if ($toolTotals !== []) {
            $rows = collect($toolTotals)
                ->map(fn (array $stats, string $name) => [
                    $name,
                    $stats['count'],
                    (int) round($stats['total_ms'] / max(1, $stats['count'])),
                ])
                ->sortByDesc(fn (array $row) => $row[2])
                ->take(5)
                ->values()
                ->all();

            $this->line('Slowest tools (avg duration):');
            $this->table(['Tool', 'Calls', 'Avg duration (ms)'], $rows);
        }
    }

    /**
     * @param  list<int>  $values
     */
    protected function percentile(array $values, int $percentile): int
    {
        if ($values === []) {
            return 0;
        }

        sort($values);
        $index = (int) ceil(($percentile / 100) * count($values)) - 1;

        return (int) ($values[max(0, $index)] ?? 0);
    }
}
