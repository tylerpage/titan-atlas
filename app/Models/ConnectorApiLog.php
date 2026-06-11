<?php

namespace App\Models;

use App\Enums\ConnectorApiLogContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorApiLog extends Model
{
    protected $fillable = [
        'connection_id',
        'connector_blueprint_id',
        'connector_type',
        'context',
        'method',
        'url',
        'status_code',
        'duration_ms',
        'stream_key',
        'resource_type',
        'request_query',
        'request_body',
        'response_body',
        'error_message',
    ];

    protected function casts(): array
    {
        return [
            'context' => ConnectorApiLogContext::class,
            'request_query' => 'array',
            'request_body' => 'array',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ConnectorBlueprint::class, 'connector_blueprint_id');
    }

    public function succeeded(): bool
    {
        return $this->status_code !== null
            && $this->status_code >= 200
            && $this->status_code < 300
            && $this->error_message === null;
    }
}
