<?php

namespace App\Services\Analytics;

use App\Enums\ConnectorType;
use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Support\DedupedRawPayloadQuery;
use App\Support\JsonPayloadSql;
use App\Support\MetricComparison;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GoogleAnalyticsDashboardService
{
    public function __construct(
        protected WidgetDataService $widgets,
        protected SeoOpportunitiesService $opportunities,
    ) {}

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
        ?Collection $connections = null,
    ): array {
        [$start, $end] = $this->widgets->resolveDateRange($dashboard, $dateRange, $customRange);
        $comparisonMode = $this->resolveComparison($comparison);
        $comparisonRange = $this->widgets->resolveComparisonRange($start, $end, $comparisonMode);

        $gscConnection = $this->gscConnectionFor($connections ?? $dashboard->connections);
        $traffic = $this->trafficTotals($connection, $start, $end);
        $comparisonTraffic = $comparisonRange
            ? $this->trafficTotals($connection, $comparisonRange[0], $comparisonRange[1])
            : null;

        $gscTotals = $gscConnection
            ? $this->gscTotals($gscConnection, $start, $end)
            : ['impressions' => 0.0, 'url_clicks' => 0.0];
        $comparisonGscTotals = ($gscConnection && $comparisonRange)
            ? $this->gscTotals($gscConnection, $comparisonRange[0], $comparisonRange[1])
            : null;

        return [
            'kind' => 'google_analytics',
            'gsc_required' => $gscConnection === null,
            'gsc_connection' => $gscConnection ? [
                'id' => $gscConnection->id,
                'name' => $gscConnection->name,
            ] : null,
            'summary' => [
                'visitors' => $traffic['visitors'],
                'active_users' => $traffic['active_users'],
                'sessions' => $traffic['sessions'],
                'impressions' => $gscTotals['impressions'],
                'url_clicks' => $gscTotals['url_clicks'],
                'visitors_change_percent' => $comparisonTraffic !== null
                    ? MetricComparison::percentChange($traffic['visitors'], $comparisonTraffic['visitors'])
                    : null,
                'active_users_change_percent' => $comparisonTraffic !== null
                    ? MetricComparison::percentChange($traffic['active_users'], $comparisonTraffic['active_users'])
                    : null,
                'sessions_change_percent' => $comparisonTraffic !== null
                    ? MetricComparison::percentChange($traffic['sessions'], $comparisonTraffic['sessions'])
                    : null,
                'impressions_change_percent' => $comparisonGscTotals !== null
                    ? MetricComparison::percentChange($gscTotals['impressions'], $comparisonGscTotals['impressions'])
                    : null,
                'url_clicks_change_percent' => $comparisonGscTotals !== null
                    ? MetricComparison::percentChange($gscTotals['url_clicks'], $comparisonGscTotals['url_clicks'])
                    : null,
            ],
            'traffic_series' => $traffic['sessions_series'],
            'comparison_traffic_series' => $comparisonTraffic['sessions_series'] ?? [],
            'events' => $this->topEvents($connection, $start, $end),
            'top_queries' => $gscConnection
                ? $this->topQueries($gscConnection, $start, $end, $comparisonRange)
                : [],
            'top_keywords' => $gscConnection
                ? $this->topKeywords($gscConnection, $start, $end)
                : [],
            'opportunities' => $this->opportunities->forConnections(
                $gscConnection,
                $connection,
                $start,
                $end,
                $comparisonRange,
            ),
        ];
    }

    /**
     * @param  Collection<int, Connection>|null  $connections
     */
    protected function gscConnectionFor(?Collection $connections): ?Connection
    {
        if ($connections === null || $connections->isEmpty()) {
            return null;
        }

        return $connections
            ->filter(fn (Connection $connection) => $connection->connector_type === ConnectorType::SearchConsole)
            ->sortBy('name')
            ->first();
    }

    /**
     * @return array{
     *     visitors: float,
     *     active_users: float,
     *     sessions: float,
     *     sessions_series: list<array{date: string, value: float}>
     * }
     */
    protected function trafficTotals(Connection $connection, Carbon $start, Carbon $end): array
    {
        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'traffic_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'date') . ' as date')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'visitors') . '), 0) as visitors')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'active_users') . '), 0) as active_users')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'sessions') . '), 0) as sessions')
            ->groupByRaw(JsonPayloadSql::text('payload', 'date'))
            ->orderBy('date')
            ->get();

        return [
            'visitors' => (float) $rows->sum('visitors'),
            'active_users' => (float) $rows->sum('active_users'),
            'sessions' => (float) $rows->sum('sessions'),
            'sessions_series' => $rows
                ->map(fn ($row) => [
                    'date' => (string) $row->date,
                    'value' => (float) $row->sessions,
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @return array{impressions: float, url_clicks: float}
     */
    protected function gscTotals(Connection $connection, Carbon $start, Carbon $end): array
    {
        $row = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'search_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->first();

        return [
            'impressions' => (float) ($row->impressions ?? 0),
            'url_clicks' => (float) ($row->clicks ?? 0),
        ];
    }

    /**
     * @return list<array{event_name: string, event_count: float}>
     */
    protected function topEvents(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.google_analytics.top_events_limit', 15));

        return DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'events_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'event_name') . ' as event_name')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'event_count') . '), 0) as event_count')
            ->groupByRaw(JsonPayloadSql::text('payload', 'event_name'))
            ->orderByDesc('event_count')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'event_name' => $this->jsonStringValue($row->event_name) ?? 'Unknown',
                'event_count' => (float) $row->event_count,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $comparisonRange
     * @return list<array<string, mixed>>
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

    /**
     * @return list<array<string, mixed>>
     */
    protected function topKeywords(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.google_analytics.top_keywords_limit', 25));

        return DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'keyword',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'keyword') . ' as keyword')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->selectRaw('coalesce(avg(' . JsonPayloadSql::real('payload', 'position') . '), 0) as avg_position')
            ->groupByRaw(JsonPayloadSql::text('payload', 'keyword'))
            ->orderByDesc('impressions')
            ->limit($limit)
            ->get()
            ->map(fn ($row) => [
                'keyword' => $this->jsonStringValue($row->keyword) ?? 'Unknown',
                'impressions' => (float) $row->impressions,
                'clicks' => (float) $row->clicks,
                'position' => round((float) $row->avg_position, 1),
            ])
            ->values()
            ->all();
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
