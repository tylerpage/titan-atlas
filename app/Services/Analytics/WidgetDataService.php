<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Enums\WidgetType;
use App\Models\ClientDashboard;
use App\Models\MetricSnapshot;
use App\Support\MetricComparison;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class WidgetDataService
{
    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     */
    public function dataFor(
        ClientDashboard $dashboard,
        WidgetType $type,
        ?string $dateRange = null,
        ?array $customRange = null,
        DateComparison|string|null $comparison = null,
    ): array {
        [$start, $end] = $this->resolveDateRange($dashboard, $dateRange, $customRange);
        $comparisonMode = $this->resolveComparison($comparison);

        $metrics = $this->metricsBetween($dashboard->id, $start, $end);
        $comparisonMetrics = $this->comparisonMetrics($dashboard->id, $start, $end, $comparisonMode);

        $data = match ($type) {
            WidgetType::Revenue => $this->series($metrics, 'revenue', $start, $end),
            WidgetType::Orders => $this->series($metrics, 'orders', $start, $end),
            WidgetType::AdSpend => $this->series($metrics, 'ad_spend', $start, $end),
            WidgetType::OrganicTraffic => $this->organicTrafficSeries($metrics, $start, $end),
            WidgetType::Roas => $this->roas($metrics),
            WidgetType::TopKeywords => $this->topKeywords($metrics),
            default => ['series' => [], 'total' => 0],
        };

        return $this->applyComparison($data, $type, $metrics, $comparisonMetrics);
    }

    /**
     * @param  array{start?: string, end?: string}|null  $customRange
     * @return array{0: Carbon, 1: Carbon}
     */
    public function resolveDateRange(
        ClientDashboard $dashboard,
        ?string $range,
        ?array $customRange = null,
    ): array {
        if ($range === 'custom' && $customRange) {
            $start = Carbon::parse($customRange['start'] ?? now()->subDays(29))->startOfDay();
            $end = Carbon::parse($customRange['end'] ?? now())->endOfDay();

            return [$start, $end];
        }

        $preset = $range ?? $dashboard->default_date_range;

        return match ($preset) {
            'last_7_days' => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
            'last_90_days' => [now()->subDays(89)->startOfDay(), now()->endOfDay()],
            'ytd' => [now()->startOfYear(), now()->endOfDay()],
            default => [now()->subDays(29)->startOfDay(), now()->endOfDay()],
        };
    }

    /**
     * @return array{0: Carbon, 1: Carbon}|null
     */
    public function resolveComparisonRange(Carbon $start, Carbon $end, DateComparison $comparison): ?array
    {
        return match ($comparison) {
            DateComparison::PreviousPeriod => $this->previousPeriodRange($start, $end),
            DateComparison::YearOverYear => [
                $start->copy()->subYear()->startOfDay(),
                $end->copy()->subYear()->endOfDay(),
            ],
            DateComparison::None => null,
        };
    }

    /**
     * @return Collection<int, MetricSnapshot>
     */
    protected function metricsBetween(int $dashboardId, Carbon $start, Carbon $end): Collection
    {
        return MetricSnapshot::query()
            ->where('client_dashboard_id', $dashboardId)
            ->whereDate('snapshot_date', '>=', $start->toDateString())
            ->whereDate('snapshot_date', '<=', $end->toDateString())
            ->get();
    }

    /**
     * @return Collection<int, MetricSnapshot>|null
     */
    protected function comparisonMetrics(
        int $dashboardId,
        Carbon $start,
        Carbon $end,
        DateComparison $comparison,
    ): ?Collection {
        $range = $this->resolveComparisonRange($start, $end, $comparison);

        if ($range === null) {
            return null;
        }

        [$compareStart, $compareEnd] = $range;

        return $this->metricsBetween($dashboardId, $compareStart, $compareEnd);
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

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    protected function previousPeriodRange(Carbon $start, Carbon $end): array
    {
        $days = $start->copy()->startOfDay()->diffInDays($end->copy()->startOfDay()) + 1;
        $compareEnd = $start->copy()->subDay()->endOfDay();
        $compareStart = $compareEnd->copy()->subDays($days - 1)->startOfDay();

        return [$compareStart, $compareEnd];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  Collection<int, MetricSnapshot>|null  $comparisonMetrics
     * @return array<string, mixed>
     */
    protected function applyComparison(
        array $data,
        WidgetType $type,
        Collection $metrics,
        ?Collection $comparisonMetrics,
    ): array {
        if ($comparisonMetrics === null) {
            return $data;
        }

        if ($type === WidgetType::Roas) {
            $comparison = $this->roas($comparisonMetrics);

            return array_merge($data, [
                'comparison' => $comparison,
                'revenue_change_percent' => MetricComparison::percentChange($data['revenue'], $comparison['revenue']),
                'ad_spend_change_percent' => MetricComparison::percentChange($data['ad_spend'], $comparison['ad_spend']),
                'roas_change_percent' => MetricComparison::percentChange($data['roas'], $comparison['roas']),
            ]);
        }

        if ($type === WidgetType::TopKeywords) {
            return $data;
        }

        $metricKey = match ($type) {
            WidgetType::Revenue => 'revenue',
            WidgetType::Orders => 'orders',
            WidgetType::AdSpend => 'ad_spend',
            WidgetType::OrganicTraffic => $this->organicTrafficMetricKey($metrics),
            default => null,
        };

        if ($metricKey === null) {
            return $data;
        }

        $comparisonTotal = (float) $comparisonMetrics->where('metric_key', $metricKey)->sum('metric_value');

        return array_merge($data, [
            'comparison_total' => $comparisonTotal,
            'change_percent' => MetricComparison::percentChange((float) $data['total'], $comparisonTotal),
        ]);
    }

    /**
     * @param  Collection<int, MetricSnapshot>  $metrics
     * @return array{series: list<array{date: string, value: float}>, total: float}
     */
    protected function series(Collection $metrics, string $key, Carbon $start, Carbon $end): array
    {
        $filtered = $metrics->where('metric_key', $key);
        $total = (float) $filtered->sum('metric_value');

        $series = $filtered
            ->groupBy(fn (MetricSnapshot $row) => $row->snapshot_date->toDateString())
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'value' => (float) $rows->sum('metric_value'),
            ])
            ->values()
            ->all();

        return compact('series', 'total');
    }

    /**
     * @param  Collection<int, MetricSnapshot>  $metrics
     */
    protected function roas(Collection $metrics): array
    {
        $revenue = (float) $metrics->where('metric_key', 'revenue')->sum('metric_value');
        $spend = (float) $metrics->where('metric_key', 'ad_spend')->sum('metric_value');

        return [
            'revenue' => $revenue,
            'ad_spend' => $spend,
            'roas' => $spend > 0 ? round($revenue / $spend, 2) : 0,
        ];
    }

    /**
     * @param  Collection<int, MetricSnapshot>  $metrics
     */
    protected function topKeywords(Collection $metrics): array
    {
        $clicksByKeyword = $metrics
            ->where('metric_key', 'search_clicks')
            ->groupBy(fn (MetricSnapshot $row) => (string) ($row->dimensions['keyword'] ?? 'Unknown'))
            ->map(fn (Collection $rows) => (float) $rows->sum('metric_value'));

        return [
            'items' => $metrics
                ->where('metric_key', 'keyword_rank')
                ->sortBy('metric_value')
                ->take(10)
                ->map(fn (MetricSnapshot $row) => [
                    'keyword' => $row->dimensions['keyword'] ?? 'Unknown',
                    'position' => (float) $row->metric_value,
                    'clicks' => (float) ($clicksByKeyword[(string) ($row->dimensions['keyword'] ?? 'Unknown')] ?? 0),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  Collection<int, MetricSnapshot>  $metrics
     * @return array{series: list<array{date: string, value: float}>, total: float}
     */
    protected function organicTrafficSeries(Collection $metrics, Carbon $start, Carbon $end): array
    {
        $key = $this->organicTrafficMetricKey($metrics);

        return $this->series($metrics, $key, $start, $end);
    }

    /**
     * @param  Collection<int, MetricSnapshot>  $metrics
     */
    protected function organicTrafficMetricKey(Collection $metrics): string
    {
        if ($metrics->where('metric_key', 'search_clicks')->isNotEmpty()) {
            return 'search_clicks';
        }

        return 'organic_sessions';
    }
}
