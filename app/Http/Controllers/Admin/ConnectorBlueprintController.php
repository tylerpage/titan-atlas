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
        $blueprint->load(['dashboard.company', 'streams', 'connection', 'builderSession']);

        return Inertia::render('Admin/Dashboards/Connections/BlueprintShow', [
            'blueprint' => $this->serializeBlueprint($blueprint),
            'dashboard' => [
                'id' => $blueprint->dashboard->id,
                'name' => $blueprint->dashboard->name,
                'company_name' => $blueprint->dashboard->company->name,
            ],
        ]);
    }

    public function test(ConnectorBlueprint $blueprint, DynamicConnector $connector): RedirectResponse
    {
        $session = $blueprint->builderSession;
        $credentials = $session?->pending_credentials ?? [];

        if ($credentials === [] && $blueprint->connection) {
            $credentials = $blueprint->connection->credentials();
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
        if ($blueprint->connection_id === null) {
            return back()->with('error', 'Create a connection before activating the blueprint.');
        }

        $blueprint->update(['status' => ConnectorBlueprintStatus::Active]);

        return back()->with('status', 'Blueprint marked as active.');
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
            'connection' => $blueprint->connection ? [
                'id' => $blueprint->connection->id,
                'name' => $blueprint->connection->name,
                'sync_status' => $blueprint->connection->sync_status->value,
                'sync_error' => $blueprint->connection->sync_error,
            ] : null,
        ];
    }
}
