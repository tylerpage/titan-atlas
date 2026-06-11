<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConnectorApiLogContext;
use App\Http\Controllers\Controller;
use App\Models\Connection;
use App\Models\ConnectorApiLog;
use App\Models\ConnectorBlueprint;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectorApiLogController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = [
            'connection_id' => $request->integer('connection_id') ?: null,
            'connector_blueprint_id' => $request->integer('connector_blueprint_id') ?: null,
            'context' => $request->string('context')->toString() ?: null,
            'status' => $request->string('status')->toString() ?: null,
            'search' => trim($request->string('search')->toString()),
        ];

        $logs = ConnectorApiLog::query()
            ->with([
                'connection:id,name,client_dashboard_id,connector_type',
                'connection.clientDashboard:id,name,company_id',
                'connection.clientDashboard.company:id,name',
                'blueprint:id,label,slug',
            ])
            ->when($filters['connection_id'], fn ($query, $id) => $query->where('connection_id', $id))
            ->when($filters['connector_blueprint_id'], fn ($query, $id) => $query->where('connector_blueprint_id', $id))
            ->when($filters['context'], fn ($query, $context) => $query->where('context', $context))
            ->when($filters['status'] === 'success', fn ($query) => $query
                ->whereBetween('status_code', [200, 299])
                ->whereNull('error_message'))
            ->when($filters['status'] === 'failed', fn ($query) => $query->where(function ($inner) {
                $inner->whereNull('status_code')
                    ->orWhere('status_code', '<', 200)
                    ->orWhere('status_code', '>=', 300)
                    ->orWhereNotNull('error_message');
            }))
            ->when($filters['search'] !== '', fn ($query) => $query->where(function ($inner) use ($filters) {
                $inner->where('url', 'like', '%'.$filters['search'].'%')
                    ->orWhere('stream_key', 'like', '%'.$filters['search'].'%')
                    ->orWhere('resource_type', 'like', '%'.$filters['search'].'%');
            }))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString()
            ->through(fn (ConnectorApiLog $log) => $this->serializeListItem($log));

        return Inertia::render('Admin/ConnectorApiLogs/Index', [
            'logs' => $logs,
            'filters' => $filters,
            'retention_hours' => (int) config('titan.connector_api_logs.retention_hours', 48),
            'filter_options' => [
                'connections' => Connection::query()
                    ->where('connector_type', 'dynamic')
                    ->orderBy('name')
                    ->get(['id', 'name'])
                    ->map(fn (Connection $connection) => [
                        'id' => $connection->id,
                        'name' => $connection->name,
                    ]),
                'blueprints' => ConnectorBlueprint::query()
                    ->orderBy('label')
                    ->get(['id', 'label', 'slug'])
                    ->map(fn (ConnectorBlueprint $blueprint) => [
                        'id' => $blueprint->id,
                        'label' => $blueprint->label,
                        'slug' => $blueprint->slug,
                    ]),
                'contexts' => collect(ConnectorApiLogContext::cases())
                    ->map(fn (ConnectorApiLogContext $context) => [
                        'value' => $context->value,
                        'label' => $context->label(),
                    ])
                    ->values(),
            ],
        ]);
    }

    public function show(ConnectorApiLog $connectorApiLog): Response
    {
        $connectorApiLog->load([
            'connection.clientDashboard.company',
            'blueprint',
        ]);

        return Inertia::render('Admin/ConnectorApiLogs/Show', [
            'log' => $this->serializeDetail($connectorApiLog),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeListItem(ConnectorApiLog $log): array
    {
        return [
            'id' => $log->id,
            'method' => $log->method,
            'url' => $log->url,
            'status_code' => $log->status_code,
            'duration_ms' => $log->duration_ms,
            'context' => $log->context->value,
            'context_label' => $log->context->label(),
            'stream_key' => $log->stream_key,
            'resource_type' => $log->resource_type,
            'succeeded' => $log->succeeded(),
            'error_message' => $log->error_message,
            'response_preview' => $log->response_body
                ? mb_substr($log->response_body, 0, 120).(strlen($log->response_body) > 120 ? '…' : '')
                : null,
            'created_at' => $log->created_at?->toIso8601String(),
            'connection' => $log->connection ? [
                'id' => $log->connection->id,
                'name' => $log->connection->name,
                'dashboard_name' => $log->connection->clientDashboard?->name,
                'company_name' => $log->connection->clientDashboard?->company?->name,
            ] : null,
            'blueprint' => $log->blueprint ? [
                'id' => $log->blueprint->id,
                'label' => $log->blueprint->label,
                'slug' => $log->blueprint->slug,
            ] : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeDetail(ConnectorApiLog $log): array
    {
        $responseBody = $log->response_body;
        $formattedResponse = null;

        if (is_string($responseBody) && $responseBody !== '') {
            $decoded = json_decode($responseBody, true);
            $formattedResponse = json_last_error() === JSON_ERROR_NONE
                ? json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
                : $responseBody;
        }

        return [
            ...$this->serializeListItem($log),
            'connector_type' => $log->connector_type,
            'request_query' => $log->request_query ?? [],
            'request_body' => $log->request_body ?? [],
            'request_headers' => $log->request_headers ?? [],
            'request_body_format' => $log->request_body_format,
            'response_body' => $responseBody,
            'response_headers' => $log->response_headers ?? [],
            'formatted_response' => $formattedResponse,
            'formatted_request_query' => $this->formatJson($log->request_query ?? []),
            'formatted_request_body' => $this->formatJson($log->request_body ?? []),
            'formatted_request_headers' => $this->formatJson($log->request_headers ?? []),
            'formatted_response_headers' => $this->formatJson($log->response_headers ?? []),
        ];
    }

    /**
     * @param  array<string, mixed>  $value
     */
    protected function formatJson(array $value): string
    {
        if ($value === []) {
            return '{}';
        }

        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
