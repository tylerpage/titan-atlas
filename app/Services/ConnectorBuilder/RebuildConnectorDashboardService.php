<?php

namespace App\Services\ConnectorBuilder;

use App\Ai\Tools\ConnectorBuilder\ProposeConnectorDashboardTool;
use App\Agents\ConnectorBuilderAgentContext;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;

class RebuildConnectorDashboardService
{
    public function __construct(protected ConnectorBlueprintDashboardVersionService $versions) {}

    /**
     * @return array<string, mixed>
     */
    public function rebuild(Connection $connection, User $user): array
    {
        $connection->loadMissing(['connectorBlueprint.streams', 'clientDashboard']);

        $blueprint = $connection->connectorBlueprint;

        if ($blueprint === null) {
            throw ValidationException::withMessages([
                'connection' => 'This connection is not linked to an AI connector blueprint.',
            ]);
        }

        $session = $blueprint->connector_builder_session_id
            ? ConnectorBuilderSession::query()->find($blueprint->connector_builder_session_id)
            : null;

        if ($session === null) {
            $session = ConnectorBuilderSession::query()->create([
                'client_dashboard_id' => $connection->client_dashboard_id,
                'user_id' => $user->id,
                'status' => ConnectorBuilderSessionStatus::Active,
                'title' => $blueprint->label,
            ]);

            $blueprint->update(['connector_builder_session_id' => $session->id]);
        }

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $connection->clientDashboard,
            user: $user,
            blueprint: $blueprint,
            connection: $connection,
        );

        $tool = app(ProposeConnectorDashboardTool::class, ['context' => $context]);

        return json_decode($tool->handle(new Request([
            'title' => $blueprint->dashboard_spec['title'] ?? ($blueprint->label.' Dashboard'),
            'saved_dashboard_title' => $blueprint->dashboard_spec['saved_dashboard_title'] ?? null,
            'rebuild' => true,
        ])), true);
    }
}
