<?php

namespace App\Models;

use App\Enums\ConnectorType;
use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Connection extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'name',
        'connector_type',
        'encrypted_credentials',
        'sync_status',
        'sync_error',
        'last_synced_at',
        'backfill_started_at',
        'backfill_completed_at',
        'settings',
        'is_active',
    ];

    protected $hidden = [
        'encrypted_credentials',
    ];

    protected function casts(): array
    {
        return [
            'connector_type' => ConnectorType::class,
            'sync_status' => SyncStatus::class,
            'encrypted_credentials' => 'encrypted:array',
            'settings' => 'array',
            'is_active' => 'boolean',
            'last_synced_at' => 'datetime',
            'backfill_started_at' => 'datetime',
            'backfill_completed_at' => 'datetime',
        ];
    }

    public function clientDashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class);
    }

    public function syncRuns(): HasMany
    {
        return $this->hasMany(SyncRun::class)->latest();
    }

    public function rawPayloads(): HasMany
    {
        return $this->hasMany(RawConnectorPayload::class);
    }

    public function credentials(): array
    {
        $credentials = $this->encrypted_credentials ?? [];

        return array_map(
            fn ($value) => is_string($value) ? trim($value) : $value,
            $credentials,
        );
    }

    public function markSyncRunning(): void
    {
        $this->update([
            'sync_status' => SyncStatus::Running,
            'sync_error' => null,
        ]);
    }

    public function markSyncSuccess(): void
    {
        $this->update([
            'sync_status' => SyncStatus::Success,
            'sync_error' => null,
            'last_synced_at' => now(),
        ]);
    }

    public function markSyncFailed(string $message): void
    {
        $this->update([
            'sync_status' => SyncStatus::Failed,
            'sync_error' => $message,
        ]);
    }
}
