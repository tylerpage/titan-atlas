<?php

namespace App\Services\Analytics;

use App\Enums\SyncStatus;
use App\Models\ClientDashboard;
use App\Support\JsonPayloadSql;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class DataQualityService
{
    /**
     * @return array{checks: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public function runChecks(ClientDashboard $dashboard, ?Carbon $start = null, ?Carbon $end = null): array
    {
        $lookbackDays = (int) config('titan.analytics_engineer.quality_lookback_days', 30);
        $threshold = (int) config('titan.analytics_engineer.anomaly_drop_threshold_percent', 50);

        $end ??= now()->endOfDay();
        $start ??= $end->copy()->subDays($lookbackDays - 1)->startOfDay();

        $checks = [];

        $checks = array_merge($checks, $this->checkSyncHealth($dashboard));
        $checks = array_merge($checks, $this->checkMissingDataDays($dashboard, $start, $end));
        $checks = array_merge($checks, $this->checkDuplicateExternalIds($dashboard));
        $checks = array_merge($checks, $this->checkZeroRevenueRate($dashboard, $start, $end));
        $checks = array_merge($checks, $this->checkPeriodDrops($dashboard, $start, $end, $threshold));

        $issues = collect($checks)->where('severity', '!=', 'ok')->count();
        $warnings = collect($checks)->where('severity', 'warning')->count();
        $errors = collect($checks)->where('severity', 'error')->count();

        return [
            'checks' => $checks,
            'summary' => [
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'total_checks' => count($checks),
                'issues' => $issues,
                'warnings' => $warnings,
                'errors' => $errors,
                'healthy' => $errors === 0,
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function checkSyncHealth(ClientDashboard $dashboard): array
    {
        $checks = [];
        $connections = $dashboard->connections()->where('is_active', true)->get();

        foreach ($connections as $connection) {
            $latestRun = $connection->syncRuns()->first();

            if ($latestRun?->status === SyncStatus::Failed) {
                $checks[] = [
                    'check' => 'sync_health',
                    'severity' => 'error',
                    'connection' => $connection->name,
                    'message' => "Latest sync failed: {$latestRun->error_message}",
                ];
            } elseif ($connection->last_synced_at === null) {
                $checks[] = [
                    'check' => 'sync_health',
                    'severity' => 'warning',
                    'connection' => $connection->name,
                    'message' => 'Connection has never synced.',
                ];
            } elseif ($connection->last_synced_at->lt(now()->subDays(2))) {
                $checks[] = [
                    'check' => 'sync_health',
                    'severity' => 'warning',
                    'connection' => $connection->name,
                    'message' => 'Last synced more than 2 days ago.',
                    'last_synced_at' => $connection->last_synced_at->toIso8601String(),
                ];
            } else {
                $checks[] = [
                    'check' => 'sync_health',
                    'severity' => 'ok',
                    'connection' => $connection->name,
                    'message' => 'Sync is healthy.',
                ];
            }
        }

        if ($connections->isEmpty()) {
            $checks[] = [
                'check' => 'sync_health',
                'severity' => 'warning',
                'message' => 'No active connections on this dashboard.',
            ];
        }

        return $checks;
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function checkMissingDataDays(ClientDashboard $dashboard, Carbon $start, Carbon $end): array
    {
        $dailyOrders = DB::table('raw_connector_payloads as r')
            ->join('connections as c', 'c.id', '=', 'r.connection_id')
            ->where('c.client_dashboard_id', $dashboard->id)
            ->where('r.resource_type', 'order')
            ->whereRaw(JsonPayloadSql::text('r.payload', 'date') . ' BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('r.payload', 'date') . ' AS day, COUNT(*) AS cnt')
            ->groupBy('day')
            ->pluck('cnt', 'day');

        $totalDays = $start->diffInDays($end) + 1;
        $daysWithData = $dailyOrders->count();
        $avgPerDay = $daysWithData > 0 ? $dailyOrders->avg() : 0;

        $zeroDays = [];
        for ($date = $start->copy(); $date->lte($end); $date->addDay()) {
            $key = $date->toDateString();
            if (! $dailyOrders->has($key) || (int) $dailyOrders[$key] === 0) {
                $zeroDays[] = $key;
            }
        }

        if (count($zeroDays) > ($totalDays * 0.5)) {
            return [[
                'check' => 'missing_data_days',
                'severity' => 'warning',
                'message' => count($zeroDays).' days with zero orders in the lookback period.',
                'zero_days' => array_slice($zeroDays, 0, 10),
                'avg_orders_per_day' => round($avgPerDay, 2),
            ]];
        }

        return [[
            'check' => 'missing_data_days',
            'severity' => 'ok',
            'message' => 'Order data coverage looks normal.',
            'days_with_data' => $daysWithData,
            'avg_orders_per_day' => round($avgPerDay, 2),
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function checkDuplicateExternalIds(ClientDashboard $dashboard): array
    {
        $duplicates = DB::table('raw_connector_payloads as r')
            ->join('connections as c', 'c.id', '=', 'r.connection_id')
            ->where('c.client_dashboard_id', $dashboard->id)
            ->select('r.connection_id', 'r.external_id', DB::raw('COUNT(*) AS cnt'))
            ->groupBy('r.connection_id', 'r.external_id')
            ->having('cnt', '>', 1)
            ->limit(5)
            ->get();

        if ($duplicates->isEmpty()) {
            return [[
                'check' => 'duplicate_external_ids',
                'severity' => 'ok',
                'message' => 'No duplicate external IDs detected.',
            ]];
        }

        return [[
            'check' => 'duplicate_external_ids',
            'severity' => 'warning',
            'message' => $duplicates->count().' duplicate external_id groups found.',
            'samples' => $duplicates->map(fn ($row) => [
                'connection_id' => $row->connection_id,
                'external_id' => $row->external_id,
                'count' => $row->cnt,
            ])->all(),
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function checkZeroRevenueRate(ClientDashboard $dashboard, Carbon $start, Carbon $end): array
    {
        $stats = DB::table('raw_connector_payloads as r')
            ->join('connections as c', 'c.id', '=', 'r.connection_id')
            ->where('c.client_dashboard_id', $dashboard->id)
            ->where('r.resource_type', 'order')
            ->whereRaw(JsonPayloadSql::text('r.payload', 'date') . ' BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('COUNT(*) AS total')
            ->selectRaw('SUM(CASE WHEN ' . JsonPayloadSql::real('r.payload', 'total') . ' = 0 OR ' . JsonPayloadSql::text('r.payload', 'total') . ' IS NULL THEN 1 ELSE 0 END) AS zero_count')
            ->first();

        $total = (int) ($stats->total ?? 0);
        $zeroCount = (int) ($stats->zero_count ?? 0);
        $rate = $total > 0 ? round(($zeroCount / $total) * 100, 1) : 0;

        if ($rate > 20) {
            return [[
                'check' => 'zero_revenue_rate',
                'severity' => 'warning',
                'message' => "{$rate}% of orders have zero or null revenue.",
                'zero_count' => $zeroCount,
                'total_orders' => $total,
            ]];
        }

        return [[
            'check' => 'zero_revenue_rate',
            'severity' => 'ok',
            'message' => 'Zero-revenue order rate is within normal range.',
            'rate_percent' => $rate,
        ]];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function checkPeriodDrops(ClientDashboard $dashboard, Carbon $start, Carbon $end, int $threshold): array
    {
        $midpoint = $start->copy()->addDays((int) floor($start->diffInDays($end) / 2));

        $firstHalf = $this->orderCount($dashboard, $start, $midpoint);
        $secondHalf = $this->orderCount($dashboard, $midpoint->copy()->addDay(), $end);

        if ($firstHalf === 0 && $secondHalf === 0) {
            return [[
                'check' => 'period_over_period_drop',
                'severity' => 'warning',
                'message' => 'No orders in either half of the lookback period.',
            ]];
        }

        if ($firstHalf === 0) {
            return [[
                'check' => 'period_over_period_drop',
                'severity' => 'ok',
                'message' => 'Insufficient baseline for period comparison.',
            ]];
        }

        $changePercent = round((($secondHalf - $firstHalf) / $firstHalf) * 100, 1);

        if ($changePercent <= -$threshold) {
            return [[
                'check' => 'period_over_period_drop',
                'severity' => 'error',
                'message' => "Orders dropped {$changePercent}% between first and second half of the period.",
                'first_half_orders' => $firstHalf,
                'second_half_orders' => $secondHalf,
                'change_percent' => $changePercent,
            ]];
        }

        return [[
            'check' => 'period_over_period_drop',
            'severity' => 'ok',
            'message' => 'No significant order volume drop detected.',
            'change_percent' => $changePercent,
        ]];
    }

    protected function orderCount(ClientDashboard $dashboard, Carbon $start, Carbon $end): int
    {
        return (int) DB::table('raw_connector_payloads as r')
            ->join('connections as c', 'c.id', '=', 'r.connection_id')
            ->where('c.client_dashboard_id', $dashboard->id)
            ->where('r.resource_type', 'order')
            ->whereRaw(JsonPayloadSql::text('r.payload', 'date') . ' BETWEEN ? AND ?', [$start->toDateString(), $end->toDateString()])
            ->count();
    }
}
