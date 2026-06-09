<?php

namespace App\Services\Admin;

use App\Enums\ConnectorType;
use App\Enums\SyncRunType;
use App\Enums\SyncStatus;
use App\Ingestion\ConnectorRegistry;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Services\Google\GoogleOAuthPendingSession;
use Illuminate\Validation\ValidationException;

class CreateConnectionService
{
    public function __construct(protected ConnectorRegistry $connectors) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(ClientDashboard $dashboard, array $data): Connection
    {
        $type = ConnectorType::from($data['connector_type']);

        $connection = new Connection([
            'client_dashboard_id' => $dashboard->id,
            'name' => $data['name'],
            'connector_type' => $type,
            'encrypted_credentials' => $this->trimCredentials($data['credentials']),
            'sync_status' => SyncStatus::Pending,
            'is_active' => true,
        ]);

        $validation = $this->connectors->make($type)->validateCredentials($connection);

        if (! $validation->valid) {
            throw ValidationException::withMessages([
                'credentials' => $validation->message ?? 'Invalid credentials.',
            ]);
        }

        $connection->save();

        if ($type->usesGoogleOAuth()) {
            app(GoogleOAuthPendingSession::class)->forget();
        }

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
