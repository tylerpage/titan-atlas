<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Support\DedupedRawPayloadQuery;
use App\Support\JsonPayloadSql;
use App\Support\MetricComparison;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class GoogleAdsDashboardService
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

        $priorYearStart = $start->copy()->subYear();
        $priorYearEnd = $end->copy()->subYear();
        $priorYearDaily = $this->dailyTotals($connection, $priorYearStart, $priorYearEnd);

        return [
            'kind' => 'google_ads',
            'summary' => [
                'cost' => $daily['cost'],
                'impressions' => $daily['impressions'],
                'clicks' => $daily['clicks'],
                'ctr' => $ctr,
                'conversions_value' => $daily['conversions_value'],
                'cost_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['cost'], $comparisonDaily['cost'])
                    : null,
                'impressions_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['impressions'], $comparisonDaily['impressions'])
                    : null,
                'clicks_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['clicks'], $comparisonDaily['clicks'])
                    : null,
                'ctr_change_percent' => $comparisonCtr !== null
                    ? MetricComparison::percentChange($ctr, $comparisonCtr)
                    : null,
                'conversions_value_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['conversions_value'], $comparisonDaily['conversions_value'])
                    : null,
            ],
            'spend_series' => $daily['cost_series'],
            'prior_year_spend_series' => $this->alignPriorYearSeries($daily['cost_series'], $priorYearDaily['cost_series']),
            'campaigns' => $this->campaignBreakdown($connection, $start, $end),
        ];
    }

    /**
     * @return array{
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     conversions_value: float,
     *     cost_series: list<array{date: string, value: float}>
     * }
     */
    protected function dailyTotals(Connection $connection, Carbon $start, Carbon $end): array
    {
        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'spend_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'date') . ' as date')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'cost') . '), 0) as cost')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'conversions_value') . '), 0) as conversions_value')
            ->groupByRaw(JsonPayloadSql::text('payload', 'date'))
            ->orderBy('date')
            ->get();

        $costSeries = $rows
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'value' => (float) $row->cost,
            ])
            ->values()
            ->all();

        return [
            'cost' => (float) $rows->sum('cost'),
            'impressions' => (float) $rows->sum('impressions'),
            'clicks' => (float) $rows->sum('clicks'),
            'conversions_value' => (float) $rows->sum('conversions_value'),
            'cost_series' => $costSeries,
        ];
    }

    /**
     * @param  list<array{date: string, value: float}>  $currentSeries
     * @param  list<array{date: string, value: float}>  $priorYearSeries
     * @return list<array{date: string, value: float}>
     */
    protected function alignPriorYearSeries(array $currentSeries, array $priorYearSeries): array
    {
        $priorByDate = [];

        foreach ($priorYearSeries as $point) {
            $priorByDate[$point['date']] = $point['value'];
        }

        $aligned = [];

        foreach ($currentSeries as $point) {
            $priorDate = Carbon::parse($point['date'])->subYear()->toDateString();
            $aligned[] = [
                'date' => $point['date'],
                'value' => (float) ($priorByDate[$priorDate] ?? 0),
            ];
        }

        return $aligned;
    }

    protected function calculateCtr(float $clicks, float $impressions): float
    {
        if ($impressions <= 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 2);
    }

    /**
     * @return list<array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     ctr: float,
     *     conversions_value: float
     * }>
     */
    protected function campaignBreakdown(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.google_ads.top_campaigns_limit', 25));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'campaign_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date') . ' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'campaign_id') . ' as campaign_id')
            ->selectRaw('max(' . JsonPayloadSql::text('payload', 'campaign_name') . ') as campaign_name')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'cost') . '), 0) as cost')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'impressions') . '), 0) as impressions')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'clicks') . '), 0) as clicks')
            ->selectRaw('coalesce(sum(' . JsonPayloadSql::real('payload', 'conversions_value') . '), 0) as conversions_value')
            ->groupByRaw(JsonPayloadSql::text('payload', 'campaign_id'))
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn ($row) => [
                'campaign_id' => (string) ($row->campaign_id ?? ''),
                'campaign_name' => (string) ($row->campaign_name ?? 'Unknown campaign'),
                'cost' => (float) $row->cost,
                'impressions' => (float) $row->impressions,
                'clicks' => (float) $row->clicks,
                'ctr' => $this->calculateCtr((float) $row->clicks, (float) $row->impressions),
                'conversions_value' => (float) $row->conversions_value,
            ])
            ->values()
            ->all();
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
