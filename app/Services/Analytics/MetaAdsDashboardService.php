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

class MetaAdsDashboardService
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
        $roas = $this->calculateRoas($daily['conversions_value'], $daily['cost']);
        $cpa = $this->calculateCpa($daily['cost'], $daily['conversions']);
        $cpc = $this->calculateCpc($daily['cost'], $daily['clicks']);
        $cpm = $this->calculateCpm($daily['cost'], $daily['impressions']);

        $comparisonCtr = $comparisonDaily !== null
            ? $this->calculateCtr($comparisonDaily['clicks'], $comparisonDaily['impressions'])
            : null;
        $comparisonRoas = $comparisonDaily !== null
            ? $this->calculateRoas($comparisonDaily['conversions_value'], $comparisonDaily['cost'])
            : null;
        $comparisonCpa = $comparisonDaily !== null
            ? $this->calculateCpa($comparisonDaily['cost'], $comparisonDaily['conversions'])
            : null;
        $comparisonCpc = $comparisonDaily !== null
            ? $this->calculateCpc($comparisonDaily['cost'], $comparisonDaily['clicks'])
            : null;

        $priorYearStart = $start->copy()->subYear();
        $priorYearEnd = $end->copy()->subYear();
        $priorYearDaily = $this->dailyTotals($connection, $priorYearStart, $priorYearEnd);

        $campaigns = $this->campaignBreakdown($connection, $start, $end);
        $topCampaigns = $this->topCampaigns($campaigns, 'cost', 10);
        $bottomCampaigns = $this->bottomCampaignsByRoas($campaigns, 10);

        return [
            'kind' => 'meta_ads',
            'currency' => $this->resolveAccountCurrency($connection),
            'summary' => [
                'cost' => $daily['cost'],
                'conversions_value' => $daily['conversions_value'],
                'roas' => $roas,
                'conversions' => $daily['conversions'],
                'cpa' => $cpa,
                'impressions' => $daily['impressions'],
                'reach' => $daily['reach'],
                'clicks' => $daily['clicks'],
                'ctr' => $ctr,
                'cpc' => $cpc,
                'cpm' => $cpm,
                'cost_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['cost'], $comparisonDaily['cost'])
                    : null,
                'conversions_value_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['conversions_value'], $comparisonDaily['conversions_value'])
                    : null,
                'roas_change_percent' => $comparisonRoas !== null && $roas !== null
                    ? MetricComparison::percentChange($roas, $comparisonRoas)
                    : null,
                'conversions_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['conversions'], $comparisonDaily['conversions'])
                    : null,
                'cpa_change_percent' => $comparisonCpa !== null && $cpa !== null
                    ? MetricComparison::percentChange($cpa, $comparisonCpa)
                    : null,
                'impressions_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['impressions'], $comparisonDaily['impressions'])
                    : null,
                'reach_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['reach'], $comparisonDaily['reach'])
                    : null,
                'clicks_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['clicks'], $comparisonDaily['clicks'])
                    : null,
                'ctr_change_percent' => $comparisonCtr !== null
                    ? MetricComparison::percentChange($ctr, $comparisonCtr)
                    : null,
                'cpc_change_percent' => $comparisonCpc !== null && $cpc !== null
                    ? MetricComparison::percentChange($cpc, $comparisonCpc)
                    : null,
            ],
            'spend_series' => $daily['cost_series'],
            'revenue_series' => $daily['revenue_series'],
            'roas_series' => $daily['roas_series'],
            'prior_year_spend_series' => $this->alignPriorYearSeries($daily['cost_series'], $priorYearDaily['cost_series']),
            'campaigns' => $campaigns,
            'top_campaigns' => $topCampaigns,
            'bottom_campaigns' => $bottomCampaigns,
            'objectives' => $this->objectiveBreakdown($connection, $start, $end),
            'placements' => $this->dimensionBreakdown($connection, $start, $end, 'placement_daily'),
            'devices' => $this->dimensionBreakdown($connection, $start, $end, 'device_daily'),
        ];
    }

    protected function resolveAccountCurrency(Connection $connection): string
    {
        $settings = $connection->settings ?? [];
        $currency = $settings['account_currency'] ?? null;

        if (is_string($currency) && $currency !== '') {
            return strtoupper($currency);
        }

        return strtoupper((string) config('titan.currency', 'USD'));
    }

    /**
     * @return array{
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     reach: float,
     *     conversions: float,
     *     conversions_value: float,
     *     cost_series: list<array{date: string, value: float}>,
     *     revenue_series: list<array{date: string, value: float}>,
     *     roas_series: list<array{date: string, value: float}>
     * }
     */
    protected function dailyTotals(Connection $connection, Carbon $start, Carbon $end): array
    {
        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'spend_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'date').' as date')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'cost').'), 0) as cost')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'impressions').'), 0) as impressions')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'clicks').'), 0) as clicks')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'reach').'), 0) as reach')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions').'), 0) as conversions')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions_value').'), 0) as conversions_value')
            ->groupByRaw(JsonPayloadSql::text('payload', 'date'))
            ->orderBy('date')
            ->get();

        $costSeries = [];
        $revenueSeries = [];
        $roasSeries = [];

        foreach ($rows as $row) {
            $date = (string) $row->date;
            $cost = (float) $row->cost;
            $revenue = (float) $row->conversions_value;

            $costSeries[] = ['date' => $date, 'value' => $cost];
            $revenueSeries[] = ['date' => $date, 'value' => $revenue];
            $roasSeries[] = [
                'date' => $date,
                'value' => $cost > 0 ? round($revenue / $cost, 2) : 0,
            ];
        }

        return [
            'cost' => (float) $rows->sum('cost'),
            'impressions' => (float) $rows->sum('impressions'),
            'clicks' => (float) $rows->sum('clicks'),
            'reach' => (float) $rows->sum('reach'),
            'conversions' => (float) $rows->sum('conversions'),
            'conversions_value' => (float) $rows->sum('conversions_value'),
            'cost_series' => $costSeries,
            'revenue_series' => $revenueSeries,
            'roas_series' => $roasSeries,
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

    /**
     * @return list<array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     objective: string,
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     reach: float,
     *     ctr: float,
     *     cpc: float,
     *     cpm: float,
     *     conversions: float,
     *     conversions_value: float,
     *     roas: float|null,
     *     cpa: float|null
     * }>
     */
    protected function campaignBreakdown(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.meta_ads.top_campaigns_limit', 100));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'campaign_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'campaign_id').' as campaign_id')
            ->selectRaw('max('.JsonPayloadSql::text('payload', 'campaign_name').') as campaign_name')
            ->selectRaw('max('.JsonPayloadSql::text('payload', 'objective').') as objective')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'cost').'), 0) as cost')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'impressions').'), 0) as impressions')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'clicks').'), 0) as clicks')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'reach').'), 0) as reach')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions').'), 0) as conversions')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions_value').'), 0) as conversions_value')
            ->groupByRaw(JsonPayloadSql::text('payload', 'campaign_id'))
            ->havingRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'cost').'), 0) > 0')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return $rows
            ->map(function ($row) {
                $cost = (float) $row->cost;
                $clicks = (float) $row->clicks;
                $impressions = (float) $row->impressions;
                $conversions = (float) $row->conversions;
                $conversionsValue = (float) $row->conversions_value;

                return [
                    'campaign_id' => (string) ($row->campaign_id ?? ''),
                    'campaign_name' => (string) ($row->campaign_name ?? 'Unknown campaign'),
                    'objective' => $this->formatObjective((string) ($row->objective ?? '')),
                    'cost' => $cost,
                    'impressions' => $impressions,
                    'clicks' => $clicks,
                    'reach' => (float) $row->reach,
                    'ctr' => $this->calculateCtr($clicks, $impressions),
                    'cpc' => $this->calculateCpc($cost, $clicks) ?? 0.0,
                    'cpm' => $this->calculateCpm($cost, $impressions) ?? 0.0,
                    'conversions' => $conversions,
                    'conversions_value' => $conversionsValue,
                    'roas' => $this->calculateRoas($conversionsValue, $cost),
                    'cpa' => $this->calculateCpa($cost, $conversions),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return list<array<string, mixed>>
     */
    protected function topCampaigns(array $campaigns, string $sortKey, int $limit): array
    {
        $sorted = $campaigns;

        usort($sorted, function (array $left, array $right) use ($sortKey) {
            $leftValue = (float) ($left[$sortKey] ?? 0);
            $rightValue = (float) ($right[$sortKey] ?? 0);

            return $rightValue <=> $leftValue;
        });

        return array_slice($sorted, 0, $limit);
    }

    /**
     * @param  list<array<string, mixed>>  $campaigns
     * @return list<array<string, mixed>>
     */
    protected function bottomCampaignsByRoas(array $campaigns, int $limit): array
    {
        $eligible = array_values(array_filter(
            $campaigns,
            fn (array $campaign) => ($campaign['cost'] ?? 0) > 0 && ($campaign['roas'] ?? null) !== null,
        ));

        usort($eligible, fn (array $left, array $right) => ((float) ($left['roas'] ?? 0)) <=> ((float) ($right['roas'] ?? 0)));

        return array_slice($eligible, 0, $limit);
    }

    /**
     * @return list<array{dimension_key: string, dimension_label: string, cost: float, conversions_value: float, conversions: float}>
     */
    protected function objectiveBreakdown(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.meta_ads.top_breakdown_limit', 15));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'campaign_daily',
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'objective').' as objective')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'cost').'), 0) as cost')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions_value').'), 0) as conversions_value')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions').'), 0) as conversions')
            ->groupByRaw(JsonPayloadSql::text('payload', 'objective'))
            ->havingRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'cost').'), 0) > 0')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn ($row) => [
                'dimension_key' => (string) ($row->objective ?? 'unknown'),
                'dimension_label' => $this->formatObjective((string) ($row->objective ?? 'Unknown')),
                'cost' => (float) $row->cost,
                'conversions_value' => (float) $row->conversions_value,
                'conversions' => (float) $row->conversions,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{dimension_key: string, dimension_label: string, cost: float, conversions_value: float, conversions: float}>
     */
    protected function dimensionBreakdown(
        Connection $connection,
        Carbon $start,
        Carbon $end,
        string $resourceType,
    ): array {
        $limit = max(1, (int) config('titan.meta_ads.top_breakdown_limit', 15));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            $resourceType,
        )
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' >= ?', [$start->toDateString()])
            ->whereRaw(JsonPayloadSql::text('payload', 'date').' <= ?', [$end->toDateString()])
            ->selectRaw(JsonPayloadSql::text('payload', 'dimension_key').' as dimension_key')
            ->selectRaw('max('.JsonPayloadSql::text('payload', 'dimension_label').') as dimension_label')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'cost').'), 0) as cost')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions_value').'), 0) as conversions_value')
            ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'conversions').'), 0) as conversions')
            ->groupByRaw(JsonPayloadSql::text('payload', 'dimension_key'))
            ->havingRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'cost').'), 0) > 0')
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn ($row) => [
                'dimension_key' => (string) ($row->dimension_key ?? ''),
                'dimension_label' => (string) ($row->dimension_label ?? 'Unknown'),
                'cost' => (float) $row->cost,
                'conversions_value' => (float) $row->conversions_value,
                'conversions' => (float) $row->conversions,
            ])
            ->values()
            ->all();
    }

    protected function formatObjective(string $objective): string
    {
        if ($objective === '') {
            return 'Unknown';
        }

        return ucwords(str_replace(['_', '.'], ' ', strtolower($objective)));
    }

    protected function calculateCtr(float $clicks, float $impressions): float
    {
        if ($impressions <= 0) {
            return 0.0;
        }

        return round(($clicks / $impressions) * 100, 2);
    }

    protected function calculateRoas(float $conversionsValue, float $cost): ?float
    {
        if ($cost <= 0) {
            return null;
        }

        return round($conversionsValue / $cost, 2);
    }

    protected function calculateCpa(float $cost, float $conversions): ?float
    {
        if ($conversions <= 0) {
            return null;
        }

        return round($cost / $conversions, 2);
    }

    protected function calculateCpc(float $cost, float $clicks): ?float
    {
        if ($clicks <= 0) {
            return null;
        }

        return round($cost / $clicks, 2);
    }

    protected function calculateCpm(float $cost, float $impressions): ?float
    {
        if ($impressions <= 0) {
            return null;
        }

        return round(($cost / $impressions) * 1000, 2);
    }

    protected function resolveComparison(DateComparison|string|null $comparison): DateComparison
    {
        if ($comparison instanceof DateComparison) {
            return $comparison;
        }

        return DateComparison::tryFrom((string) $comparison) ?? DateComparison::None;
    }
}
