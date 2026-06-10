<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorBlueprintStream extends Model
{
    protected $fillable = [
        'connector_blueprint_id',
        'stream_key',
        'resource_type',
        'http_method',
        'path_template',
        'query_params',
        'request_body',
        'request_body_format',
        'headers',
        'pagination',
        'response_mapping',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'query_params' => 'array',
            'request_body' => 'array',
            'headers' => 'array',
            'pagination' => 'array',
            'response_mapping' => 'array',
            'enabled' => 'boolean',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ConnectorBlueprint::class, 'connector_blueprint_id');
    }
}
