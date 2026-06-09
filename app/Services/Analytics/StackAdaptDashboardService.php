<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Support\DedupedRawPayloadQuery;
use App\Support\MetricComparison;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class StackAdaptDashboardService
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
        $comparisonCtr = $comparisonDaily !== null
            ? $this->calculateCtr($comparisonDaily['clicks'], $comparisonDaily['impressions'])
            : null;
        $comparisonRoas = $comparisonDaily !== null
            ? $this->calculateRoas($comparisonDaily['conversions_value'], $comparisonDaily['cost'])
            : null;

        $priorYearStart = $start->copy()->subYear();
        $priorYearEnd = $end->copy()->subYear();
        $priorYearDaily = $this->dailyTotals($connection, $priorYearStart, $priorYearEnd);

        $channels = $this->channelBreakdown($connection, $start, $end);
        $videoAudio = $this->videoAudioSummary($channels);

        return [
            'kind' => 'stackadapt',
            'summary' => [
                'cost' => $daily['cost'],
                'impressions' => $daily['impressions'],
                'clicks' => $daily['clicks'],
                'ctr' => $ctr,
                'conversions' => $daily['conversions'],
                'conversions_value' => $daily['conversions_value'],
                'secondary_conversions' => $daily['secondary_conversions'],
                'roas' => $roas,
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
                'conversions_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['conversions'], $comparisonDaily['conversions'])
                    : null,
                'conversions_value_change_percent' => $comparisonDaily !== null
                    ? MetricComparison::percentChange($daily['conversions_value'], $comparisonDaily['conversions_value'])
                    : null,
                'roas_change_percent' => $comparisonRoas !== null
                    ? MetricComparison::percentChange($roas, $comparisonRoas)
                    : null,
            ],
            'spend_series' => $daily['cost_series'],
            'prior_year_spend_series' => $this->alignPriorYearSeries($daily['cost_series'], $priorYearDaily['cost_series']),
            'channels' => $channels,
            'video_audio' => $videoAudio,
            'campaigns' => $this->campaignBreakdown($connection, $start, $end),
            'top_geos' => $this->insightBreakdown($connection, $start, $end, 'insight_geo_daily'),
            'top_domains' => $this->insightBreakdown($connection, $start, $end, 'insight_domain_daily'),
            'devices' => $this->insightBreakdown($connection, $start, $end, 'insight_device_daily'),
        ];
    }

    /**
     * @return array{
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     conversions: float,
     *     conversions_value: float,
     *     secondary_conversions: float,
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
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("json_extract(payload, '$.date') as date")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.cost') as real)), 0) as cost")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.impressions') as real)), 0) as impressions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.clicks') as real)), 0) as clicks")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.conversions') as real)), 0) as conversions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.conversions_value') as real)), 0) as conversions_value")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.secondary_conversions') as real)), 0) as secondary_conversions")
            ->groupByRaw("json_extract(payload, '$.date')")
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
            'conversions' => (float) $rows->sum('conversions'),
            'conversions_value' => (float) $rows->sum('conversions_value'),
            'secondary_conversions' => (float) $rows->sum('secondary_conversions'),
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

    protected function calculateRoas(float $conversionsValue, float $cost): ?float
    {
        if ($cost <= 0) {
            return null;
        }

        return round($conversionsValue / $cost, 2);
    }

    /**
     * @return list<array{
     *     channel_type: string,
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     ctr: float,
     *     conversions: float,
     *     conversions_value: float,
     *     video_starts: float,
     *     audio_starts: float
     * }>
     */
    protected function channelBreakdown(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.stackadapt.top_channels_limit', 10));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'channel_daily',
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("json_extract(payload, '$.channel_type') as channel_type")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.cost') as real)), 0) as cost")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.impressions') as real)), 0) as impressions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.clicks') as real)), 0) as clicks")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.conversions') as real)), 0) as conversions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.conversions_value') as real)), 0) as conversions_value")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.video_starts') as real)), 0) as video_starts")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.audio_starts') as real)), 0) as audio_starts")
            ->groupByRaw("json_extract(payload, '$.channel_type')")
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn ($row) => [
                'channel_type' => (string) ($row->channel_type ?? 'Unknown'),
                'cost' => (float) $row->cost,
                'impressions' => (float) $row->impressions,
                'clicks' => (float) $row->clicks,
                'ctr' => $this->calculateCtr((float) $row->clicks, (float) $row->impressions),
                'conversions' => (float) $row->conversions,
                'conversions_value' => (float) $row->conversions_value,
                'video_starts' => (float) $row->video_starts,
                'audio_starts' => (float) $row->audio_starts,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array{channel_type: string, video_starts: float, audio_starts: float}>  $channels
     * @return array{show: bool, video_starts: float, audio_starts: float}
     */
    protected function videoAudioSummary(array $channels): array
    {
        $videoStarts = 0.0;
        $audioStarts = 0.0;

        foreach ($channels as $channel) {
            $videoStarts += (float) ($channel['video_starts'] ?? 0);
            $audioStarts += (float) ($channel['audio_starts'] ?? 0);
        }

        return [
            'show' => $videoStarts > 0 || $audioStarts > 0,
            'video_starts' => $videoStarts,
            'audio_starts' => $audioStarts,
        ];
    }

    /**
     * @return list<array{
     *     campaign_id: string,
     *     campaign_name: string,
     *     campaign_group_name: string,
     *     channel_type: string,
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     ctr: float,
     *     conversions: float,
     *     conversions_value: float
     * }>
     */
    protected function campaignBreakdown(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.stackadapt.top_campaigns_limit', 25));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'campaign_daily',
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("json_extract(payload, '$.campaign_id') as campaign_id")
            ->selectRaw("max(json_extract(payload, '$.campaign_name')) as campaign_name")
            ->selectRaw("max(json_extract(payload, '$.campaign_group_name')) as campaign_group_name")
            ->selectRaw("max(json_extract(payload, '$.channel_type')) as channel_type")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.cost') as real)), 0) as cost")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.impressions') as real)), 0) as impressions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.clicks') as real)), 0) as clicks")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.conversions') as real)), 0) as conversions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.conversions_value') as real)), 0) as conversions_value")
            ->groupByRaw("json_extract(payload, '$.campaign_id')")
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn ($row) => [
                'campaign_id' => (string) ($row->campaign_id ?? ''),
                'campaign_name' => (string) ($row->campaign_name ?? 'Unknown campaign'),
                'campaign_group_name' => (string) ($row->campaign_group_name ?? ''),
                'channel_type' => (string) ($row->channel_type ?? ''),
                'cost' => (float) $row->cost,
                'impressions' => (float) $row->impressions,
                'clicks' => (float) $row->clicks,
                'ctr' => $this->calculateCtr((float) $row->clicks, (float) $row->impressions),
                'conversions' => (float) $row->conversions,
                'conversions_value' => (float) $row->conversions_value,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array{
     *     dimension_key: string,
     *     dimension_label: string,
     *     cost: float,
     *     impressions: float,
     *     clicks: float,
     *     conversions: float
     * }>
     */
    protected function insightBreakdown(
        Connection $connection,
        Carbon $start,
        Carbon $end,
        string $resourceType,
    ): array {
        $limit = max(1, (int) config('titan.stackadapt.top_insights_limit', 15));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            $resourceType,
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("json_extract(payload, '$.dimension_key') as dimension_key")
            ->selectRaw("max(json_extract(payload, '$.dimension_label')) as dimension_label")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.cost') as real)), 0) as cost")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.impressions') as real)), 0) as impressions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.clicks') as real)), 0) as clicks")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.conversions') as real)), 0) as conversions")
            ->groupByRaw("json_extract(payload, '$.dimension_key')")
            ->orderByDesc('cost')
            ->limit($limit)
            ->get();

        return $rows
            ->map(fn ($row) => [
                'dimension_key' => (string) ($row->dimension_key ?? ''),
                'dimension_label' => (string) ($row->dimension_label ?? 'Unknown'),
                'cost' => (float) $row->cost,
                'impressions' => (float) $row->impressions,
                'clicks' => (float) $row->clicks,
                'conversions' => (float) $row->conversions,
            ])
            ->values()
            ->all();
    }

    protected function resolveComparison(DateComparison|string|null $comparison): DateComparison
    {
        if ($comparison instanceof DateComparison) {
            return $comparison;
        }

        return DateComparison::tryFrom((string) $comparison) ?? DateComparison::None;
    }
}
