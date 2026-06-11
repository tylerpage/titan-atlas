<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Services\Admin\GatheredAnalyticsBrowseService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class GatheredAnalyticsController extends Controller
{
    public function index(Request $request, GatheredAnalyticsBrowseService $browser): Response
    {
        $filters = $this->filtersFromRequest($request);
        $view = $filters['view'];

        $summary = $browser->summary($filters['connection_id'], $filters['dashboard_id']);

        if ($view === 'metrics') {
            $records = $browser->paginateMetrics($filters);

            return Inertia::render('Admin/GatheredAnalytics/Index', [
                'view' => 'metrics',
                'filters' => $filters,
                'summary' => $summary,
                'records' => $records->through(fn (MetricSnapshot $metric) => $this->serializeMetric($metric)),
                'filter_options' => [
                    'connections' => $browser->connectionOptions(),
                    'dashboards' => $browser->dashboardOptions(),
                    'resource_types' => $summary['resource_types'],
                    'metric_keys' => $summary['metric_keys'],
                ],
                'sort_options' => [
                    ['value' => 'snapshot_date', 'label' => 'Date'],
                    ['value' => 'metric_key', 'label' => 'Metric'],
                    ['value' => 'metric_value', 'label' => 'Value'],
                    ['value' => 'id', 'label' => 'ID'],
                ],
            ]);
        }

        $records = $browser->paginatePayloads($filters);

        return Inertia::render('Admin/GatheredAnalytics/Index', [
            'view' => 'payloads',
            'filters' => $filters,
            'summary' => $summary,
            'records' => $records->through(fn (RawConnectorPayload $payload) => $this->serializePayloadSummary($payload)),
            'filter_options' => [
                'connections' => $browser->connectionOptions(),
                'dashboards' => $browser->dashboardOptions(),
                'resource_types' => $summary['resource_types'],
                'metric_keys' => $summary['metric_keys'],
            ],
            'sort_options' => [
                ['value' => 'fetched_at', 'label' => 'Fetched at'],
                ['value' => 'payload_date', 'label' => 'Payload date'],
                ['value' => 'resource_type', 'label' => 'Resource type'],
                ['value' => 'external_id', 'label' => 'External ID'],
                ['value' => 'id', 'label' => 'ID'],
            ],
        ]);
    }

    public function showPayload(RawConnectorPayload $payload): Response
    {
        $payload->load([
            'connection.clientDashboard.company',
            'syncRun:id,type,status,started_at,finished_at',
        ]);

        return Inertia::render('Admin/GatheredAnalytics/PayloadShow', [
            'payload' => $this->serializePayloadDetail($payload),
        ]);
    }

    public function showMetric(MetricSnapshot $metric): Response
    {
        $metric->load('clientDashboard.company');

        return Inertia::render('Admin/GatheredAnalytics/MetricShow', [
            'metric' => $this->serializeMetricDetail($metric),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function filtersFromRequest(Request $request): array
    {
        $view = $request->string('view')->toString() === 'metrics' ? 'metrics' : 'payloads';

        return [
            'view' => $view,
            'connection_id' => $request->integer('connection_id') ?: null,
            'dashboard_id' => $request->integer('dashboard_id') ?: null,
            'resource_type' => $request->string('resource_type')->toString() ?: null,
            'metric_key' => $request->string('metric_key')->toString() ?: null,
            'date_from' => $request->string('date_from')->toString() ?: null,
            'date_to' => $request->string('date_to')->toString() ?: null,
            'search' => trim($request->string('search')->toString()),
            'sort' => $request->string('sort')->toString() ?: ($view === 'metrics' ? 'snapshot_date' : 'fetched_at'),
            'direction' => $request->string('direction')->toString() === 'asc' ? 'asc' : 'desc',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializePayloadSummary(RawConnectorPayload $payload): array
    {
        $preview = json_encode($payload->payload ?? [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';

        return [
            'id' => $payload->id,
            'resource_type' => $payload->resource_type,
            'external_id' => $payload->external_id,
            'payload_date' => $payload->payload_date?->toDateString(),
            'fetched_at' => $payload->fetched_at?->toIso8601String(),
            'payload_preview' => mb_strlen($preview) > 120 ? mb_substr($preview, 0, 120).'…' : $preview,
            'connection' => $payload->connection ? [
                'id' => $payload->connection->id,
                'name' => $payload->connection->name,
                'connector_type' => $payload->connection->connector_type->value,
                'dashboard_name' => $payload->connection->clientDashboard?->name,
                'company_name' => $payload->connection->clientDashboard?->company?->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializePayloadDetail(RawConnectorPayload $payload): array
    {
        $formattedPayload = json_encode(
            $payload->payload ?? [],
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        ) ?: '{}';

        return [
            ...$this->serializePayloadSummary($payload),
            'payload_hash' => $payload->payload_hash,
            'sync_run' => $payload->syncRun ? [
                'id' => $payload->syncRun->id,
                'type' => $payload->syncRun->type,
                'status' => $payload->syncRun->status,
                'started_at' => $payload->syncRun->started_at?->toIso8601String(),
                'finished_at' => $payload->syncRun->finished_at?->toIso8601String(),
            ] : null,
            'formatted_payload' => $formattedPayload,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMetric(MetricSnapshot $metric): array
    {
        return [
            'id' => $metric->id,
            'snapshot_date' => $metric->snapshot_date?->toDateString(),
            'metric_key' => $metric->metric_key,
            'metric_value' => (float) $metric->metric_value,
            'currency' => $metric->currency,
            'dimensions' => $metric->dimensions ?? [],
            'dashboard' => $metric->clientDashboard ? [
                'id' => $metric->clientDashboard->id,
                'name' => $metric->clientDashboard->name,
                'company_name' => $metric->clientDashboard->company?->name,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMetricDetail(MetricSnapshot $metric): array
    {
        return [
            ...$this->serializeMetric($metric),
            'formatted_dimensions' => json_encode(
                $metric->dimensions ?? [],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ) ?: '{}',
        ];
    }
}
