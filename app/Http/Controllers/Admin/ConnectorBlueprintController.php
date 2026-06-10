<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConnectorBlueprintStatus;
use App\Http\Controllers\Controller;
use App\Ingestion\Connectors\DynamicConnector;
use App\Models\ConnectorBlueprint;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class ConnectorBlueprintController extends Controller
{
    public function show(ConnectorBlueprint $blueprint): Response
    {
        $blueprint->load(['company', 'dashboard.company', 'streams', 'connections.clientDashboard', 'builderSession']);

        return Inertia::render('Admin/Dashboards/Connections/BlueprintShow', [
            'blueprint' => $this->serializeBlueprint($blueprint),
            'dashboard' => $blueprint->dashboard ? [
                'id' => $blueprint->dashboard->id,
                'name' => $blueprint->dashboard->name,
                'company_name' => $blueprint->dashboard->company->name,
            ] : null,
            'company' => [
                'id' => $blueprint->company_id,
                'name' => $blueprint->company?->name,
            ],
        ]);
    }

    public function test(ConnectorBlueprint $blueprint, DynamicConnector $connector): RedirectResponse
    {
        $session = $blueprint->builderSession;
        $credentials = $session?->pending_credentials ?? [];

        if ($credentials === []) {
            $connection = $blueprint->connections()->latest()->first();

            if ($connection !== null) {
                $credentials = $connection->credentials();
            }
        }

        if ($credentials === []) {
            return back()->with('error', 'No credentials available to test this blueprint.');
        }

        try {
            $connector->probeConnection($blueprint, $credentials);
            $blueprint->update(['status' => ConnectorBlueprintStatus::Ready]);

            return back()->with('status', 'Connection test succeeded.');
        } catch (\Throwable $e) {
            $blueprint->update(['status' => ConnectorBlueprintStatus::Failed]);

            return back()->with('error', $e->getMessage());
        }
    }

    public function activate(ConnectorBlueprint $blueprint): RedirectResponse
    {
        if (! $blueprint->connections()->exists()) {
            return back()->with('error', 'Add at least one dashboard connection before activating this AI connector.');
        }

        $blueprint->update(['status' => ConnectorBlueprintStatus::Active]);

        return back()->with('status', 'AI connector marked as active.');
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeBlueprint(ConnectorBlueprint $blueprint): array
    {
        return [
            'id' => $blueprint->id,
            'slug' => $blueprint->slug,
            'label' => $blueprint->label,
            'status' => $blueprint->status->value,
            'is_shared' => $blueprint->isShared(),
            'original_prompt' => $blueprint->original_prompt,
            'auth_config' => $blueprint->auth_config ?? [],
            'credential_schema' => $blueprint->credential_schema ?? [],
            'sync_config' => $blueprint->sync_config ?? [],
            'transform_config' => $blueprint->transform_config ?? [],
            'dashboard_spec' => $blueprint->dashboard_spec ?? [],
            'dev_tasks' => $blueprint->dev_tasks ?? [],
            'streams' => $blueprint->streams->map(fn ($stream) => [
                'id' => $stream->id,
                'stream_key' => $stream->stream_key,
                'resource_type' => $stream->resource_type,
                'http_method' => $stream->http_method,
                'path_template' => $stream->path_template,
                'enabled' => $stream->enabled,
            ])->values()->all(),
            'connections' => $blueprint->connections->map(fn ($connection) => [
                'id' => $connection->id,
                'name' => $connection->name,
                'sync_status' => $connection->sync_status->value,
                'sync_error' => $connection->sync_error,
                'dashboard' => [
                    'id' => $connection->clientDashboard->id,
                    'name' => $connection->clientDashboard->name,
                ],
            ])->values()->all(),
        ];
    }
}
