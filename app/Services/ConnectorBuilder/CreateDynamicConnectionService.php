<?php

namespace App\Services\ConnectorBuilder;

use App\Enums\ConnectorType;
use App\Enums\SyncRunType;
use App\Enums\SyncStatus;
use App\Ingestion\ConnectorRegistry;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CreateDynamicConnectionService
{
    public function __construct(
        protected ConnectorRegistry $connectors,
        protected ConnectorBlueprintDashboardVersionService $layouts,
        protected RebuildConnectorDashboardService $dashboardRebuild,
    ) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function create(
        ClientDashboard $dashboard,
        ConnectorBlueprint $blueprint,
        string $name,
        array $credentials,
        ?User $user = null,
    ): Connection {
        $connection = new Connection([
            'client_dashboard_id' => $dashboard->id,
            'name' => $name,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'encrypted_credentials' => $this->trimCredentials($credentials),
            'sync_status' => SyncStatus::Pending,
            'is_active' => true,
        ]);

        $validation = $this->connectors->make(ConnectorType::Dynamic)->validateCredentials($connection);

        if (! $validation->valid) {
            throw ValidationException::withMessages([
                'credentials' => $validation->message ?? 'Invalid credentials.',
            ]);
        }

        $connection->save();

        SyncConnectionJob::dispatch($connection, SyncRunType::Backfill);

        if ($user !== null) {
            $this->autoBuildDashboardIfNeeded($connection, $blueprint, $user);
        }

        return $connection;
    }

    protected function autoBuildDashboardIfNeeded(
        Connection $connection,
        ConnectorBlueprint $blueprint,
        User $user,
    ): void {
        if ($this->layouts->currentSpec($blueprint, $connection->clientDashboard) !== null) {
            return;
        }

        if (! $this->layouts->hasWidgetTemplate($blueprint)) {
            return;
        }

        try {
            $result = $this->dashboardRebuild->rebuild($connection, $user);

            if (! ($result['success'] ?? false)) {
                Log::warning('connector.auto_build_dashboard_failed', [
                    'connection_id' => $connection->id,
                    'blueprint_id' => $blueprint->id,
                    'dashboard_id' => $connection->client_dashboard_id,
                    'error' => $result['error'] ?? 'Unknown error',
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('connector.auto_build_dashboard_failed', [
                'connection_id' => $connection->id,
                'blueprint_id' => $blueprint->id,
                'dashboard_id' => $connection->client_dashboard_id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $credentials
     * @return array<string, mixed>
     */
    protected function trimCredentials(array $credentials): array
    {
        return array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $credentials,
        );
    }
}
