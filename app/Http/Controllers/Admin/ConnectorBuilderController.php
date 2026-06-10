<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\SendConnectorBuilderMessageRequest;
use App\Models\ClientDashboard;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBuilderSession;
use App\Services\AI\ConnectorBuilderAgentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
            'session' => $session ? $this->serializeSession($session) : null,
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
        );

        return redirect()
            ->route('admin.dashboards.connections.ai-create', [
                'dashboard' => $dashboard->id,
                'session' => $result['session']->id,
            ])
            ->with('status', 'The connector builder is working on your request…');
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
    protected function serializeSession(ConnectorBuilderSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'status' => $session->status->value,
            'pending_credentials' => array_keys($session->pending_credentials ?? []),
            'messages' => $session->messages->map(fn ($message) => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => $message->content,
                'metadata' => $message->metadata,
            ])->values()->all(),
            'blueprint' => $session->blueprint ? $this->serializeBlueprint($session->blueprint) : null,
        ];
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
            'credential_schema' => $blueprint->credential_schema ?? [],
            'sync_config' => $blueprint->sync_config ?? [],
            'transform_config' => $blueprint->transform_config ?? [],
            'dashboard_spec' => $blueprint->dashboard_spec ?? [],
            'dev_tasks' => $blueprint->dev_tasks ?? [],
            'streams' => $blueprint->streams->map(fn ($stream) => [
                'stream_key' => $stream->stream_key,
                'resource_type' => $stream->resource_type,
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
}
