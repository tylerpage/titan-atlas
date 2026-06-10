<?php

namespace App\Services\Analytics;

use App\Models\Connection;
use App\Support\DedupedRawPayloadQuery;
use App\Support\JsonPayloadSql;
use App\Support\MetricComparison;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class SeoOpportunitiesService
{
    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $comparisonRange
     * @return array{
     *     high_impression_low_ctr: list<array<string, mixed>>,
     *     striking_distance: list<array<string, mixed>>,
     *     traffic_drop_pages: list<array<string, mixed>>
     * }
     */
    public function forConnections(
        ?Connection $gscConnection,
        Connection $ga4Connection,
        Carbon $start,
        Carbon $end,
        ?array $comparisonRange,
    ): array {
        $config = config('titan.google_analytics.opportunities', []);
        $limit = max(1, (int) ($config['limit'] ?? 10));

        return [
            'high_impression_low_ctr' => $gscConnection
                ? $this->highImpressionLowCtr($gscConnection, $start, $end, $config, $limit)
                : [],
            'striking_distance' => $gscConnection
                ? $this->strikingDistance($gscConnection, $start, $end, $config, $limit)
                : [],
            'traffic_drop_pages' => $this->trafficDropPages($ga4Connection, $start, $end, $comparisonRange, $config, $limit),
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    protected function highImpressionLowCtr(
        Connection $connection,
        Carbon $start,
        Carbon $end,
        array $config,
        int $limit,
    ): array {
        $minImpressions = (float) ($config['min_impressions'] ?? 50);
        $multiplier = (float) ($config['low_ctr_multiplier'] ?? 0.5);

        $siteCtr = $this->siteCtr($connection, $start, $end);
        $ctrThreshold = $siteCtr * $multiplier;

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
            ->havingRaw('impressions >= ?', [$minImpressions])
            ->orderByDesc('impressions')
            ->get();

        return $rows
            ->map(function ($row) use ($ctrThreshold) {
                $impressions = (float) $row->impressions;
                $clicks = (float) $row->clicks;
                $ctr = $impressions > 0 ? ($clicks / $impressions) * 100 : 0.0;

                return [
                    'query' => $this->jsonStringValue($row->query) ?? 'Unknown',
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'ctr' => round($ctr, 2),
                ];
            })
            ->filter(fn (array $row) => $row['ctr'] <= $ctrThreshold)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    protected function strikingDistance(
        Connection $connection,
        Carbon $start,
        Carbon $end,
        array $config,
        int $limit,
    ): array {
        $minPosition = (float) ($config['striking_distance_min'] ?? 4);
        $maxPosition = (float) ($config['striking_distance_max'] ?? 10);
        $minImpressions = (float) ($config['min_impressions'] ?? 50);

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
            ->selectRaw('coalesce(avg(' . JsonPayloadSql::real('payload', 'position') . '), 0) as avg_position')
            ->groupByRaw(JsonPayloadSql::text('payload', 'keyword'))
            ->havingRaw('impressions >= ?', [$minImpressions])
            ->orderByDesc('impressions')
            ->get();

        return $rows
            ->map(fn ($row) => [
                'query' => $this->jsonStringValue($row->query) ?? 'Unknown',
                'impressions' => (float) $row->impressions,
                'clicks' => (float) $row->clicks,
                'avg_position' => round((float) $row->avg_position, 1),
            ])
            ->filter(fn (array $row) => $row['avg_position'] >= $minPosition && $row['avg_position'] <= $maxPosition)
            ->take($limit)
            ->values()
            ->all();
    }

    /**
     * @param  array{0: Carbon, 1: Carbon}|null  $comparisonRange
     * @param  array<string, mixed>  $config
     * @return list<array<string, mixed>>
     */
    protected function trafficDropPages(
        Connection $connection,
        Carbon $start,
        Carbon $end,
        ?array $comparisonRange,
        array $config,
        int $limit,
    ): array {
        if ($comparisonRange === null) {
            return [];
        }

        $dropPercent = (float) ($config['traffic_drop_percent'] ?? 30);

        $current = $this->landingPageSessions($connection, $start, $end);
        $previous = $this->landingPageSessions($connection, $comparisonRange[0], $comparisonRange[1]);

        $results = [];

        foreach ($current as $page => $sessions) {
            $previousSessions = (float) ($previous[$page] ?? 0.0);

            if ($previousSessions <= 0) {
                continue;
            }

            $changePercent = MetricComparison::percentChange($sessions, $previousSessions);

            if ($changePercent === null || $changePercent > -$dropPercent) {
                continue;
            }

            $results[] = [
                'landing_page' => $page,
                'sessions' => $sessions,
                'previous_sessions' => $previousSessions,
                'change_percent' => $changePercent,
            ];
        }

        usort($results, fn (array $a, array $b) => $a['change_percent'] <=> $b['change_percent']);

        return array_slice($results, 0, $limit);
    }

    /**
     * @return array<string, float>
     */
    protected function landingPageSessions(Connection $connection, Carbon $start, Carbon $end): array
    {
        return DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'landing_page',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'landing_page') . ' as landing_page')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'sessions') . '), 0) as sessions')
            ->groupByRaw(JsonPayloadSql::text('payload', 'landing_page'))
            ->get()
            ->mapWithKeys(fn ($row) => [
                $this->jsonStringValue($row->landing_page) ?? 'Unknown' => (float) $row->sessions,
            ])
            ->all();
    }

    protected function siteCtr(Connection $connection, Carbon $start, Carbon $end): float
    {
        $row = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'search_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
            ->first();

        $impressions = (float) ($row->impressions ?? 0);

        if ($impressions <= 0) {
            return 0.0;
        }

        return ((float) ($row->clicks ?? 0) / $impressions) * 100;
    }

    protected function jsonStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value, '"');

        return $string !== '' ? $string : null;
    }
}
