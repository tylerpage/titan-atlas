<?php

namespace App\Console\Commands;

use App\Models\AnalyticsReportSession;
use Illuminate\Console\Command;
class AiStatsCommand extends Command
{
    protected $signature = 'titan:ai-stats
                            {--days=7 : Look back this many days}
                            {--dashboard= : Filter to a client dashboard ID}';

    protected $description = 'Summarize TitanAI session durations and fast-path usage';

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

            return self::SUCCESS;
        }

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

        return self::SUCCESS;
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
