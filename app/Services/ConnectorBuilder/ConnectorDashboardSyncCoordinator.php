<?php

namespace App\Services\ConnectorBuilder;

use App\Enums\UserRole;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;

class ConnectorDashboardSyncCoordinator
{
    public function __construct(
        protected ConnectorBlueprintDashboardVersionService $layouts,
        protected RebuildConnectorDashboardService $dashboardRebuild,
    ) {}

    public function afterSuccessfulSync(Connection $connection, SyncRun $syncRun, int $recordsWritten): void
    {
        if (! $connection->isDynamic() || $recordsWritten <= 0) {
            return;
        }

        $connection->loadMissing(['connectorBlueprint', 'clientDashboard']);

        $blueprint = $connection->connectorBlueprint;

        if ($blueprint === null) {
            return;
        }

        $user = $this->resolveUser($connection);

        if ($user === null) {
            Log::warning('connector.dashboard_sync_coordinator_no_user', [
                'connection_id' => $connection->id,
                'sync_run_id' => $syncRun->id,
            ]);

            return;
        }

        $spec = $this->layouts->currentSpec($blueprint, $connection->clientDashboard);

        if ($spec === null) {
            if ($this->layouts->hasWidgetTemplate($blueprint)) {
                $this->rebuild($connection, $user, 'auto_build_after_sync');
            }

            return;
        }

        if (($spec['pending_payload_refresh'] ?? false) === true) {
            $this->rebuild($connection, $user, 'refresh_after_first_payloads');
        }
    }

    protected function rebuild(Connection $connection, User $user, string $reason): void
    {
        try {
            $result = $this->dashboardRebuild->rebuild($connection, $user);

            if (! ($result['success'] ?? false)) {
                Log::warning('connector.dashboard_sync_coordinator_rebuild_failed', [
                    'connection_id' => $connection->id,
                    'reason' => $reason,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('connector.dashboard_sync_coordinator_rebuild_failed', [
                'connection_id' => $connection->id,
                'reason' => $reason,
                'error' => $e->getMessage(),
            ]);
        }
    }

    protected function resolveUser(Connection $connection): ?User
    {
        $sessionUserId = $connection->connectorBlueprint?->builderSession?->user_id;

        if (is_numeric($sessionUserId)) {
            $user = User::query()->find((int) $sessionUserId);

            if ($user !== null) {
                return $user;
            }
        }

        return User::query()->where('role', UserRole::Admin)->orderBy('id')->first();
    }
}
