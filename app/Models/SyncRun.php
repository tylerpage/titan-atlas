<?php

namespace App\Models;

use App\Enums\SyncRunType;
use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SyncRun extends Model
{
    protected $fillable = [
        'connection_id',
        'type',
        'status',
        'records_fetched',
        'records_written',
        'error_code',
        'error_message',
        'error_payload',
        'started_at',
        'finished_at',
    ];

    protected function casts(): array
    {
        return [
            'type' => SyncRunType::class,
            'status' => SyncStatus::class,
            'error_payload' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function rawPayloads(): HasMany
    {
        return $this->hasMany(RawConnectorPayload::class);
    }

    public function markRunning(): void
    {
        $this->update([
            'status' => SyncStatus::Running,
            'started_at' => now(),
        ]);
    }

    public function markFinished(SyncStatus $status, int $fetched = 0, int $written = 0): void
    {
        $this->update([
            'status' => $status,
            'records_fetched' => $fetched,
            'records_written' => $written,
            'finished_at' => now(),
        ]);
    }

    public function markFailed(string $message, ?string $code = null, ?array $payload = null): void
    {
        $this->update([
            'status' => SyncStatus::Failed,
            'error_code' => $code,
            'error_message' => $message,
            'error_payload' => $payload,
            'finished_at' => now(),
        ]);
    }
}
