<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RawConnectorPayload extends Model
{
    protected $fillable = [
        'connection_id',
        'sync_run_id',
        'resource_type',
        'external_id',
        'payload',
        'payload_date',
        'payload_hash',
        'fetched_at',
    ];

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'payload_date' => 'date',
            'fetched_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SyncRun::class);
    }
}
