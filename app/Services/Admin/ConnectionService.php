<?php

namespace App\Services\Admin;

use App\Enums\SyncStatus;
use App\Ingestion\ConnectorRegistry;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Google\GoogleOAuthPendingSession;
use Illuminate\Validation\ValidationException;

class ConnectionService
{
    public function __construct(protected ConnectorRegistry $connectors) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Connection $connection, array $data): Connection
    {
        $credentials = $connection->credentials();

        foreach ($connection->connector_type->credentialKeys() as $key) {
            $value = $data['credentials'][$key] ?? null;

            if (is_string($value) && trim($value) !== '') {
                $credentials[$key] = trim($value);
            }
        }

        $connection->fill([
            'name' => $data['name'],
            'encrypted_credentials' => $credentials,
        ]);

        if (array_key_exists('is_active', $data)) {
            $connection->is_active = (bool) $data['is_active'];
        }

        $validation = $this->connectors->make($connection->connector_type)->validateCredentials($connection);

        if (! $validation->valid) {
            throw ValidationException::withMessages([
                'credentials' => $validation->message ?? 'Invalid credentials.',
            ]);
        }

        $connection->save();

        if ($connection->connector_type->usesGoogleOAuth()) {
            app(GoogleOAuthPendingSession::class)->forget(
                $connection->client_dashboard_id,
                $connection->connector_type,
            );
        }

        return $connection->fresh();
    }

    public function delete(Connection $connection): void
    {
        $this->deleteMetricsForConnection($connection);
        $connection->delete();
    }

    /**
     * @return array{payloads: int, sync_runs: int, metrics: int}
     */
    public function clearData(Connection $connection): array
    {
        if ($connection->sync_status === SyncStatus::Running) {
            throw ValidationException::withMessages([
                'connection' => 'Cannot clear data while a sync is running.',
            ]);
        }

        $payloads = RawConnectorPayload::query()
            ->where('connection_id', $connection->id)
            ->delete();

        $syncRuns = SyncRun::query()
            ->where('connection_id', $connection->id)
            ->delete();

        $metrics = $this->deleteMetricsForConnection($connection);

        $connection->update([
            'sync_status' => SyncStatus::Pending,
            'sync_error' => null,
            'last_synced_at' => null,
            'backfill_started_at' => null,
            'backfill_completed_at' => null,
        ]);

        return [
            'payloads' => $payloads,
            'sync_runs' => $syncRuns,
            'metrics' => $metrics,
        ];
    }

    protected function deleteMetricsForConnection(Connection $connection): int
    {
        return MetricSnapshot::query()
            ->where('client_dashboard_id', $connection->client_dashboard_id)
            ->where('dimensions->connection_id', $connection->id)
            ->delete();
    }
}
