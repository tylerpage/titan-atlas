<?php

namespace App\Services\Admin;

use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;

class GatheredAnalyticsBrowseService
{
    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, RawConnectorPayload>
     */
    public function paginatePayloads(array $filters): LengthAwarePaginator
    {
        $query = RawConnectorPayload::query()
            ->with([
                'connection:id,name,client_dashboard_id,connector_type',
                'connection.clientDashboard:id,name,company_id',
                'connection.clientDashboard.company:id,name',
            ])
            ->when($filters['connection_id'] ?? null, fn (Builder $query, $id) => $query->where('connection_id', $id))
            ->when($filters['dashboard_id'] ?? null, fn (Builder $query, $id) => $query->whereHas(
                'connection',
                fn (Builder $connectionQuery) => $connectionQuery->where('client_dashboard_id', $id),
            ))
            ->when($filters['resource_type'] ?? null, fn (Builder $query, $type) => $query->where('resource_type', $type))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('payload_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('payload_date', '<=', $date))
            ->when(($filters['search'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $search = (string) $filters['search'];
                $query->where(function (Builder $inner) use ($search): void {
                    $inner->where('external_id', 'like', '%'.$search.'%')
                        ->orWhere('resource_type', 'like', '%'.$search.'%');
                });
            });

        $sort = (string) ($filters['sort'] ?? 'fetched_at');
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $sortColumn = match ($sort) {
            'id', 'resource_type', 'external_id', 'payload_date', 'fetched_at' => $sort,
            default => 'fetched_at',
        };

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, MetricSnapshot>
     */
    public function paginateMetrics(array $filters): LengthAwarePaginator
    {
        $query = MetricSnapshot::query()
            ->with('clientDashboard.company')
            ->when($filters['dashboard_id'] ?? null, fn (Builder $query, $id) => $query->where('client_dashboard_id', $id))
            ->when($filters['connection_id'] ?? null, fn (Builder $query, $id) => $query->where('dimensions->connection_id', (int) $id))
            ->when($filters['metric_key'] ?? null, fn (Builder $query, $key) => $query->where('metric_key', $key))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('snapshot_date', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('snapshot_date', '<=', $date))
            ->when(($filters['search'] ?? '') !== '', function (Builder $query) use ($filters): void {
                $search = (string) $filters['search'];
                $query->where('metric_key', 'like', '%'.$search.'%');
            });

        $sort = (string) ($filters['sort'] ?? 'snapshot_date');
        $direction = ($filters['direction'] ?? 'desc') === 'asc' ? 'asc' : 'desc';

        $sortColumn = match ($sort) {
            'id', 'snapshot_date', 'metric_key', 'metric_value' => $sort,
            default => 'snapshot_date',
        };

        return $query
            ->orderBy($sortColumn, $direction)
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();
    }

    /**
     * @return array{
     *     payload_count: int,
     *     metric_count: int,
     *     resource_types: list<string>,
     *     metric_keys: list<string>
     * }
     */
    public function summary(?int $connectionId = null, ?int $dashboardId = null): array
    {
        $payloadQuery = RawConnectorPayload::query()
            ->when($connectionId, fn (Builder $query) => $query->where('connection_id', $connectionId))
            ->when($dashboardId, fn (Builder $query) => $query->whereHas(
                'connection',
                fn (Builder $connectionQuery) => $connectionQuery->where('client_dashboard_id', $dashboardId),
            ));

        $metricQuery = MetricSnapshot::query()
            ->when($dashboardId, fn (Builder $query) => $query->where('client_dashboard_id', $dashboardId))
            ->when($connectionId, fn (Builder $query) => $query->where('dimensions->connection_id', $connectionId));

        return [
            'payload_count' => (int) (clone $payloadQuery)->count(),
            'metric_count' => (int) (clone $metricQuery)->count(),
            'resource_types' => (clone $payloadQuery)
                ->select('resource_type')
                ->distinct()
                ->orderBy('resource_type')
                ->pluck('resource_type')
                ->all(),
            'metric_keys' => (clone $metricQuery)
                ->select('metric_key')
                ->distinct()
                ->orderBy('metric_key')
                ->pluck('metric_key')
                ->all(),
        ];
    }

    /**
     * @return list<array{id: int, name: string, dashboard_name: string|null, company_name: string|null}>
     */
    public function connectionOptions(): array
    {
        return Connection::query()
            ->with('clientDashboard.company')
            ->orderBy('name')
            ->get(['id', 'name', 'client_dashboard_id'])
            ->map(fn (Connection $connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'dashboard_id' => $connection->client_dashboard_id,
                'dashboard_name' => $connection->clientDashboard?->name,
                'company_name' => $connection->clientDashboard?->company?->name,
            ])
            ->all();
    }

    /**
     * @return list<array{id: int, name: string, company_name: string|null}>
     */
    public function dashboardOptions(): array
    {
        return ClientDashboard::query()
            ->with('company')
            ->orderBy('name')
            ->get(['id', 'name', 'company_id'])
            ->map(fn (ClientDashboard $dashboard) => [
                'id' => $dashboard->id,
                'name' => $dashboard->name,
                'company_name' => $dashboard->company?->name,
            ])
            ->all();
    }
}
