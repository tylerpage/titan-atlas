<?php

namespace App\Services\ConnectorBuilder;

use App\Enums\ConnectorBuilderSessionStatus;
use App\Models\ClientDashboard;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class ConnectorBuilderResumeService
{
    /**
     * @return array{session: ConnectorBuilderSession, dashboard: ClientDashboard}
     */
    public function resolve(ConnectorBlueprint $blueprint, User $user): array
    {
        $blueprint->loadMissing(['builderSession.dashboard', 'connections.clientDashboard', 'dashboard']);

        $session = $blueprint->builderSession;

        if ($session === null) {
            $session = $this->attachSession($blueprint, $user);
        } elseif ($session->status === ConnectorBuilderSessionStatus::Failed) {
            $session->update(['status' => ConnectorBuilderSessionStatus::Active]);
        }

        $dashboard = $session->dashboard ?? $this->resolveDashboard($blueprint);

        if ($dashboard === null) {
            throw ValidationException::withMessages([
                'blueprint' => 'This AI connector does not have a dashboard context to resume chat from yet.',
            ]);
        }

        return [
            'session' => $session->fresh(['messages', 'blueprint.streams', 'blueprint.connections']),
            'dashboard' => $dashboard,
        ];
    }

    protected function attachSession(ConnectorBlueprint $blueprint, User $user): ConnectorBuilderSession
    {
        $dashboard = $this->resolveDashboard($blueprint);

        if ($dashboard === null) {
            throw ValidationException::withMessages([
                'blueprint' => 'This AI connector does not have a dashboard context to resume chat from yet.',
            ]);
        }

        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => ConnectorBuilderSessionStatus::Active,
            'title' => $blueprint->label,
        ]);

        $blueprint->update(['connector_builder_session_id' => $session->id]);

        return $session;
    }

    protected function resolveDashboard(ConnectorBlueprint $blueprint): ?ClientDashboard
    {
        if ($blueprint->client_dashboard_id !== null) {
            return $blueprint->dashboard;
        }

        $connection = $blueprint->connections()->with('clientDashboard')->oldest()->first();

        if ($connection?->clientDashboard !== null) {
            return $connection->clientDashboard;
        }

        if ($blueprint->company_id === null) {
            return null;
        }

        return ClientDashboard::query()
            ->where('company_id', $blueprint->company_id)
            ->orderBy('id')
            ->first();
    }
}
