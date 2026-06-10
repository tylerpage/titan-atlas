<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Support\ConnectorDataLag;
use App\Support\DedupedRawPayloadQuery;
use App\Support\JsonPayloadSql;
use App\Support\MetricComparison;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SearchConsoleDashboardService
{
    public function __construct(protected WidgetDataService $widgets) {}

    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     * @return array<string, mixed>
     */
    public function dataFor(
        ClientDashboard $dashboard,
        Connection $connection,
        ?string $dateRange = null,
        ?array $customRange = null,
        DateComparison|string|null $comparison = null,
    ): array {
        [$start, $end] = $this->widgets->resolveDateRange($dashboard, $dateRange, $customRange);
        $comparisonMode = $this->resolveComparison($comparison);
        $comparisonRange = $this->widgets->resolveComparisonRange($start, $end, $comparisonMode);

        $daily = $this->dailyTotals($connection, $start, $end);
        $comparisonDaily = $comparisonRange
            ? $this->dailyTotals($connection, $comparisonRange[0], $comparisonRange[1])
            : null;

        $ctr = $this->calculateCtr($daily['clicks'], $daily['impressions']);
        $comparisonCtr = $comparisonDaily !== null
            ? $this->calculateCtr($comparisonDaily['clicks'], $comparisonDaily['impressions'])
            : null;

        return [
            'kind' => 'search_console',
            'data_lag' => ConnectorDataLag::forConfigKey('search_console', 3),
            'summary' => [
                'impressions' => $daily['impressions'],
                'clicks' => $daily['clicks'],
                'ctr' => $ctr,
                'impressions_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['impressions'], $comparisonDaily['impressions'])
                    : null,
                'clicks_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['clicks'], $comparisonDaily['clicks'])
                    : null,
                'ctr_change_percent' => $comparisonCtr !== null
                    ? MetricComparison::percentChange($ctr, $comparisonCtr)
                    : null,
                'comparison_impressions' => $comparisonDaily['impressions'] ?? null,
                'comparison_clicks' => $comparisonDaily['clicks'] ?? null,
                'comparison_ctr' => $comparisonCtr,
            ],
            'impressions_series' => $daily['impressions_series'],
            'clicks_series' => $daily['clicks_series'],
            'comparison_impressions_series' => $comparisonRange
                ? $this->dailyTotals($connection, $comparisonRange[0], $comparisonRange[1])['impressions_series']
                : [],
            'comparison_clicks_series' => $comparisonRange
                ? $this->dailyTotals($connection, $comparisonRange[0], $comparisonRange[1])['clicks_series']
                : [],
            'device_breakdown' => $this->deviceBreakdown($connection, $start, $end),
            'top_queries' => $this->topQueries($connection, $start, $end, $comparisonRange),
        ];
    }

    /**
     * @return array{
     *     impressions: float,
     *     clicks: float,
     *     impressions_series: list<array{date: string, value: float}>,
     *     clicks_series: list<array{date: string, value: float}>
     * }
     */
    protected function dailyTotals(Connection $connection, Carbon $start, Carbon $end): array
    {
        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'search_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'date') . ' as date')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->groupByRaw(JsonPayloadSql::text('payload', 'date'))
            ->orderBy('date')
            ->get();

        $impressionsSeries = $rows
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'value' => (float) $row->impressions,
            ])
            ->values()
            ->all();

        $clicksSeries = $rows
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'value' => (float) $row->clicks,
            ])
            ->values()
            ->all();

        return [
            'impressions' => (float) $rows->sum('impressions'),
            'clicks' => (float) $rows->sum('clicks'),
            'impressions_series' => $impressionsSeries,
            'clicks_series' => $clicksSeries,
        ];
    }

    protected function calculateCtr(float $clicks, float $impressions): float
    {
        if ($impressions <= 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 2);
    }

    /**
     * @return list<array{device: string, clicks: float, share_percent: float}>
     */
    protected function deviceBreakdown(Connection $connection, Carbon $start, Carbon $end): array
    {
        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'search_device',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'device') . ' as device')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->groupByRaw(JsonPayloadSql::text('payload', 'device'))
            ->orderByDesc('clicks')
            ->get();

        $totalClicks = (float) $rows->sum('clicks');

        if ($totalClicks <= 0) {
            return [];
        }

        return $rows
            ->map(fn ($row) => [
                'device' => $this->formatDeviceLabel((string) ($row->device ?? '')),
                'clicks' => (float) $row->clicks,
                'share_percent' => round(((float) $row->clicks / $totalClicks) * 100, 1),
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $comparisonRange
     * @return list<array{query: string, impressions: float, clicks: float, impressions_change_percent: float|null}>
     */
    protected function topQueries(
        Connection $connection,
        Carbon $start,
        Carbon $end,
        ?array $comparisonRange,
    ): array {
        $limit = max(1, (int) config('titan.search_console.top_queries_limit', 25));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'keyword',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'keyword') . ' as query')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->groupByRaw(JsonPayloadSql::text('payload', 'keyword'))
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get();

        $comparisonByQuery = [];

        if ($comparisonRange !== null) {
            $comparisonByQuery = DedupedRawPayloadQuery::applyToQueryBuilder(
                DB::table('raw_connector_payloads'),
                $connection->id,
                'keyword',
            )
                ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$comparisonRange[0]->toDateString()])
                ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$comparisonRange[1]->toDateString()])
                ->selectRaw(JsonPayloadSql::text('payload', 'keyword') . ' as query')
                ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
                ->groupByRaw(JsonPayloadSql::text('payload', 'keyword'))
                ->get()
                ->mapWithKeys(fn ($row) => [
                    $this->jsonStringValue($row->query) => (float) $row->impressions,
                ])
                ->all();
        }

        return $rows
            ->map(function ($row) use ($comparisonByQuery, $comparisonRange) {
                $query = $this->jsonStringValue($row->query) ?? 'Unknown';
                $impressions = (float) $row->impressions;
                $previousImpressions = $comparisonRange !== null
                    ? (float) ($comparisonByQuery[$query] ?? 0.0)
                    : null;

                return [
                    'query' => $query,
                    'impressions' => $impressions,
                    'clicks' => (float) $row->clicks,
                    'impressions_change_percent' => $previousImpressions !== null
                        ? MetricComparison::percentChange($impressions, $previousImpressions)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    protected function formatDeviceLabel(string $device): string
    {
        return match (strtoupper($device)) {
            'MOBILE' => 'Mobile',
            'DESKTOP' => 'Desktop',
            'TABLET' => 'Tablet',
            default => $device !== '' ? ucfirst(strtolower($device)) : 'Unknown',
        };
    }

    protected function jsonStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value, '"');

        return $string !== '' ? $string : null;
    }

    protected function resolveComparison(DateComparison|string|null $comparison): DateComparison
    {
        if ($comparison instanceof DateComparison) {
            return $comparison;
        }

        if (is_string($comparison)) {
            return DateComparison::tryFrom($comparison) ?? DateComparison::None;
        }

        return DateComparison::None;
    }
}
