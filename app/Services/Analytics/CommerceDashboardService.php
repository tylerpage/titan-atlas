<?php

namespace App\Services\Analytics;

use App\Enums\DateComparison;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Support\DedupedRawPayloadQuery;
use App\Support\MetricComparison;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class CommerceDashboardService
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

        $revenue = $this->buildSeriesFromPayloads($connection, 'revenue', $start, $end);
        $orders = $this->buildSeriesFromPayloads($connection, 'orders', $start, $end);

        $comparisonRevenue = $comparisonRange
            ? $this->buildSeriesFromPayloads($connection, 'revenue', $comparisonRange[0], $comparisonRange[1])['total']
            : null;

        $comparisonOrders = $comparisonRange
            ? $this->buildSeriesFromPayloads($connection, 'orders', $comparisonRange[0], $comparisonRange[1])['total']
            : null;

        $avgOrderValue = $this->averageOrderValue($revenue['total'], $orders['total']);
        $comparisonAvgOrderValue = $comparisonRange
            ? $this->averageOrderValue($comparisonRevenue ?? 0.0, $comparisonOrders ?? 0.0)
            : null;

        $sessions = $this->sessionTotals($connection, $start, $end);
        $comparisonSessions = $comparisonRange
            ? $this->sessionTotals($connection, $comparisonRange[0], $comparisonRange[1])
            : null;

        return [
            'kind' => 'commerce',
            'summary' => [
                'revenue' => $revenue['total'],
                'orders' => $orders['total'],
                'sessions' => $sessions['total'],
                'visitors' => $sessions['visitors'],
                'avg_order_value' => $avgOrderValue,
                'revenue_change_percent' => $comparisonRevenue !== null
                    ? MetricComparison::percentChange($revenue['total'], $comparisonRevenue)
                    : null,
                'orders_change_percent' => $comparisonOrders !== null
                    ? MetricComparison::percentChange($orders['total'], $comparisonOrders)
                    : null,
                'avg_order_value_change_percent' => $comparisonAvgOrderValue !== null
                    ? MetricComparison::percentChange($avgOrderValue, $comparisonAvgOrderValue)
                    : null,
                'sessions_change_percent' => $comparisonSessions !== null
                    ? MetricComparison::percentChange($sessions['total'], $comparisonSessions['total'])
                    : null,
                'comparison_revenue' => $comparisonRevenue,
                'comparison_orders' => $comparisonOrders,
                'comparison_avg_order_value' => $comparisonAvgOrderValue,
                'comparison_sessions' => $comparisonSessions['total'] ?? null,
            ],
            'revenue_series' => $revenue['series'],
            'comparison_revenue_series' => $comparisonRange
                ? $this->buildSeriesFromPayloads($connection, 'revenue', $comparisonRange[0], $comparisonRange[1])['series']
                : [],
            'orders' => $this->orderRows($connection, $start, $end),
            'top_products' => $this->topProducts($connection, $start, $end),
            'sessions_by_source_medium' => $this->sessionsBySourceMedium($connection, $start, $end),
        ];
    }

    /**
     * @return array{series: list<array{date: string, value: float}>, total: float}
     */
    protected function buildSeriesFromPayloads(
        Connection $connection,
        string $key,
        Carbon $start,
        Carbon $end,
    ): array {
        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'order',
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("json_extract(payload, '$.date') as date")
            ->selectRaw('count(*) as orders')
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.total') as real)), 0) as revenue")
            ->groupByRaw("json_extract(payload, '$.date')")
            ->orderBy('date')
            ->get();

        $valueColumn = $key === 'orders' ? 'orders' : 'revenue';
        $total = (float) $rows->sum($valueColumn);

        $series = $rows
            ->map(fn ($row) => [
                'date' => (string) $row->date,
                'value' => (float) $row->{$valueColumn},
            ])
            ->values()
            ->all();

        return compact('series', 'total');
    }

    /**
     * @return Collection<int, MetricSnapshot>
     */
    protected function metricsForConnection(
        int $dashboardId,
        int $connectionId,
        Carbon $start,
        Carbon $end,
    ): Collection {
        return MetricSnapshot::query()
            ->where('client_dashboard_id', $dashboardId)
            ->whereIn('metric_key', ['revenue', 'orders'])
            ->whereDate('snapshot_date', '>=', $start->toDateString())
            ->whereDate('snapshot_date', '<=', $end->toDateString())
            ->where('dimensions->connection_id', $connectionId)
            ->get();
    }

    /**
     * @param  Collection<int, MetricSnapshot>  $metrics
     * @return array{series: list<array{date: string, value: float}>, total: float}
     */
    protected function buildSeries(Collection $metrics, string $key, Carbon $start, Carbon $end): array
    {
        $filtered = $metrics->where('metric_key', $key);
        $total = (float) $filtered->sum('metric_value');

        $series = $filtered
            ->groupBy(fn (MetricSnapshot $row) => $row->snapshot_date->toDateString())
            ->map(fn ($rows, $date) => [
                'date' => $date,
                'value' => (float) $rows->sum('metric_value'),
            ])
            ->sortKeys()
            ->values()
            ->all();

        return compact('series', 'total');
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function orderRows(Connection $connection, Carbon $start, Carbon $end): array
    {
        $pageSize = (int) config('titan.commerce.orders_page_size', 50);

        return DedupedRawPayloadQuery::applyToEloquent(
            RawConnectorPayload::query()->where('connection_id', $connection->id),
            $connection->id,
            'order',
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->orderByRaw("json_extract(payload, '$.date') DESC")
            ->limit($pageSize)
            ->get()
            ->map(function (RawConnectorPayload $row) {
                $payload = $row->payload ?? [];

                return [
                    'external_id' => $row->external_id,
                    'order_number' => $payload['order_number'] ?? $row->external_id,
                    'date' => $payload['date'] ?? null,
                    'total' => (float) ($payload['total'] ?? 0),
                    'source_medium' => $payload['source_medium'] ?? null,
                    'source' => $payload['source'] ?? null,
                    'medium' => $payload['medium'] ?? null,
                    'channel' => $payload['channel'] ?? null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function topProducts(Connection $connection, Carbon $start, Carbon $end): array
    {
        $limit = max(1, (int) config('titan.commerce.top_products_limit', 10));

        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'order_line_item',
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("coalesce(nullif(json_extract(payload, '$.sku'), ''), json_extract(payload, '$.name')) as product_key")
            ->selectRaw("json_extract(payload, '$.sku') as sku")
            ->selectRaw("json_extract(payload, '$.name') as name")
            ->selectRaw("json_extract(payload, '$.image_url') as image_url")
            ->selectRaw("sum(cast(json_extract(payload, '$.quantity') as real)) as units_sold")
            ->selectRaw("sum(cast(json_extract(payload, '$.line_total') as real)) as revenue")
            ->groupByRaw("product_key, json_extract(payload, '$.sku'), json_extract(payload, '$.name'), json_extract(payload, '$.image_url')")
            ->orderByDesc('revenue')
            ->limit($limit)
            ->get();

        return $rows->map(function ($row) {
            return [
                'sku' => $this->jsonStringValue($row->sku),
                'name' => $this->jsonStringValue($row->name) ?: 'Unknown product',
                'image_url' => $this->jsonStringValue($row->image_url),
                'units_sold' => (float) $row->units_sold,
                'revenue' => (float) $row->revenue,
            ];
        })->values()->all();
    }

    protected function jsonStringValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value, '"');

        return $string !== '' ? $string : null;
    }

    /**
     * @return list<array{date: string, value: float}>
     */
    public function ordersSeriesFor(Connection $connection, Carbon $start, Carbon $end): array
    {
        return $this->buildSeriesFromPayloads($connection, 'orders', $start, $end)['series'];
    }

    /**
     * @return array{total: float, visitors: float}
     */
    protected function sessionTotals(Connection $connection, Carbon $start, Carbon $end): array
    {
        $rows = DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'session_attribution',
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.sessions') as real)), 0) as sessions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.visitors') as real)), 0) as visitors")
            ->first();

        return [
            'total' => (float) ($rows->sessions ?? 0),
            'visitors' => (float) ($rows->visitors ?? 0),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    protected function sessionsBySourceMedium(Connection $connection, Carbon $start, Carbon $end): array
    {
        return DedupedRawPayloadQuery::applyToQueryBuilder(
            DB::table('raw_connector_payloads'),
            $connection->id,
            'session_attribution',
        )
            ->whereRaw("json_extract(payload, '$.date') >= ?", [$start->toDateString()])
            ->whereRaw("json_extract(payload, '$.date') <= ?", [$end->toDateString()])
            ->selectRaw("json_extract(payload, '$.source_medium') as source_medium")
            ->selectRaw("json_extract(payload, '$.source') as source")
            ->selectRaw("json_extract(payload, '$.medium') as medium")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.sessions') as real)), 0) as sessions")
            ->selectRaw("coalesce(sum(cast(json_extract(payload, '$.visitors') as real)), 0) as visitors")
            ->groupByRaw("json_extract(payload, '$.source_medium')")
            ->groupByRaw("json_extract(payload, '$.source')")
            ->groupByRaw("json_extract(payload, '$.medium')")
            ->orderByDesc('sessions')
            ->get()
            ->map(fn ($row) => [
                'source_medium' => (string) ($row->source_medium ?? '(not set)'),
                'source' => (string) ($row->source ?? '(not set)'),
                'medium' => (string) ($row->medium ?? '(not set)'),
                'sessions' => (float) $row->sessions,
                'visitors' => (float) $row->visitors,
            ])
            ->values()
            ->all();
    }

    protected function averageOrderValue(float $revenue, float $orders): float
    {
        if ($orders <= 0) {
            return 0.0;
        }

        return round($revenue / $orders, 2);
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
