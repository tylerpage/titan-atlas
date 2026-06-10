<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendConnectorBuilderMessageRequest;
use App\Models\ClientDashboard;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintDashboardVersion;
use App\Models\ConnectorBuilderSession;
use App\Services\AI\ConnectorBuilderAgentService;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class ConnectorBuilderController extends Controller
{
    public function aiCreate(
        ClientDashboard $dashboard,
        ?ConnectorBuilderSession $session = null,
    ): Response {
        $dashboard->load('company');

        if ($session !== null) {
            abort_unless($session->client_dashboard_id === $dashboard->id, 404);
            $session->load(['messages', 'blueprint.streams', 'blueprint.connections']);
        }

        return Inertia::render('Admin/Dashboards/Connections/AiCreate', [
            'dashboard' => $this->serializeDashboard($dashboard),
            'session' => $session ? $this->serializeSession($session, $dashboard) : null,
            'isResuming' => $session !== null && (
                $session->blueprint !== null || $session->messages->isNotEmpty()
            ),
        ]);
    }

    public function sessionStatus(ClientDashboard $dashboard, ConnectorBuilderSession $session): JsonResponse
    {
        abort_unless($session->client_dashboard_id === $dashboard->id, 404);

        $session->refresh();

        return response()->json([
            'status' => $session->status->value,
            'messages_count' => $session->messages()->count(),
        ]);
    }

    public function sendMessage(
        SendConnectorBuilderMessageRequest $request,
        ClientDashboard $dashboard,
        ConnectorBuilderAgentService $agent,
    ): RedirectResponse {
        $session = $request->filled('session_id')
            ? ConnectorBuilderSession::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->findOrFail($request->integer('session_id'))
            : null;

        $result = $agent->queueMessage(
            dashboard: $dashboard,
            user: $request->user(),
            message: $request->string('message')->toString(),
            session: $session,
            credentials: $request->input('credentials'),
            sessionConfig: $request->input('session_config'),
        );

        return redirect()
            ->route('admin.dashboards.connections.ai-create', [
                'dashboard' => $dashboard->id,
                'session' => $result['session']->id,
            ])
            ->with('status', 'The connector builder is working on your request…');
    }

    public function revertDashboard(
        Request $request,
        ClientDashboard $dashboard,
        ConnectorBlueprint $blueprint,
        ConnectorBlueprintDashboardVersionService $versions,
    ): RedirectResponse {
        abort_unless($request->user()?->isAdmin(), 403);
        abort_unless($blueprint->connector_builder_session_id !== null, 404);

        $session = ConnectorBuilderSession::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->whereKey($blueprint->connector_builder_session_id)
            ->firstOrFail();

        abort_unless($session->blueprint?->is($blueprint), 404);

        $version = ConnectorBlueprintDashboardVersion::query()
            ->where('connector_blueprint_id', $blueprint->id)
            ->where('client_dashboard_id', $dashboard->id)
            ->findOrFail($request->integer('version_id'));

        $versions->revert($blueprint, $dashboard, $version);

        return redirect()
            ->route('admin.dashboards.connections.ai-create', [
                'dashboard' => $dashboard->id,
                'session' => $session->id,
            ])
            ->with('status', "Reverted dashboard to version {$version->version_number}.");
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeDashboard(ClientDashboard $dashboard): array
    {
        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'company_name' => $dashboard->company->name,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeSession(ConnectorBuilderSession $session, ClientDashboard $dashboard): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status->value,
            'pending_credentials' => array_keys($session->pending_credentials ?? []),
            'session_config' => $session->session_config ?? [],
            'messages' => $session->messages->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'metadata' => $message->metadata,
            ])->values()->all(),
            'blueprint' => $session->blueprint ? $this->serializeBlueprint($session->blueprint, $dashboard) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeBlueprint(ConnectorBlueprint $blueprint, ClientDashboard $dashboard): array
    {
        return [
            'id' => $blueprint->id,
            'slug' => $blueprint->slug,
            'label' => $blueprint->label,
            'status' => $blueprint->status->value,
            'is_global' => $blueprint->isGlobal(),
            'original_prompt' => $blueprint->original_prompt,
            'auth_config' => $blueprint->auth_config ?? [],
            'credential_schema' => $blueprint->credential_schema ?? [],
            'sync_config' => $blueprint->sync_config ?? [],
            'requires_base_url_per_dashboard' => \App\Support\DynamicConnectorBaseUrl::requiresPerDashboard($blueprint),
            'transform_config' => $blueprint->transform_config ?? [],
            'dashboard_spec' => $this->serializeDashboardSpec($blueprint, $dashboard),
            'dev_tasks' => $blueprint->dev_tasks ?? [],
            'streams' => $blueprint->streams->map(fn ($stream) => [
                'stream_key' => $stream->stream_key,
                'resource_type' => $stream->resource_type,
                'http_method' => $stream->http_method,
                'path_template' => $stream->path_template,
                'enabled' => $stream->enabled,
            ])->values()->all(),
            'connection' => ($connection = $blueprint->connections->first()) ? [
                'id' => $connection->id,
                'name' => $connection->name,
                'sync_status' => $connection->sync_status->value,
                'sync_error' => $connection->sync_error,
            ] : null,
            'connections' => $blueprint->connections->map(fn ($item) => [
                'id' => $item->id,
                'name' => $item->name,
                'sync_status' => $item->sync_status->value,
            ])->values()->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeDashboardSpec(ConnectorBlueprint $blueprint, ClientDashboard $dashboard): array
    {
        $layouts = app(ConnectorBlueprintDashboardVersionService::class);
        $layoutSpec = $layouts->currentSpec($blueprint, $dashboard);
        $templateSpec = is_array($blueprint->dashboard_spec) ? $blueprint->dashboard_spec : [];

        if ($layoutSpec === null && $templateSpec === []) {
            return [];
        }

        $spec = array_merge($templateSpec, $layoutSpec ?? []);

        $savedDashboardId = $layoutSpec['saved_dashboard_id'] ?? null;

        if (is_numeric($savedDashboardId)) {
            $boardExists = \App\Models\SavedDashboard::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->whereKey((int) $savedDashboardId)
                ->exists();

            if ($boardExists) {
                $spec['saved_dashboard_url'] = route('client.dashboard.saved.show', [
                    'dashboard' => $dashboard->slug,
                    'board' => (int) $savedDashboardId,
                ]);
            } else {
                unset($spec['saved_dashboard_url']);
            }
        }

        $spec['versions'] = $layouts->listForBlueprint($blueprint, $dashboard);
        $spec['has_dashboard_layout'] = $layoutSpec !== null && is_numeric($layoutSpec['saved_dashboard_id'] ?? null);

        return $spec;
    }
}
