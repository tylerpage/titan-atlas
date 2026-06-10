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
use Illuminate\Validation\ValidationException;

class CreateDynamicConnectionService
{
    public function __construct(protected ConnectorRegistry $connectors) {}

    /**
     * @param  array<string, mixed>  $credentials
     */
    public function create(
        ClientDashboard $dashboard,
        ConnectorBlueprint $blueprint,
        string $name,
        array $credentials,
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

        $blueprint->update(['connection_id' => $connection->id]);

        SyncConnectionJob::dispatch($connection, SyncRunType::Backfill);

        return $connection;
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
