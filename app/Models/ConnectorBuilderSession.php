<?php

namespace App\Models;

use App\Enums\ConnectorBuilderSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ConnectorBuilderSession extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'user_id',
        'status',
        'title',
        'duration_ms',
        'pending_credentials',
    ];

    protected function casts(): array
    {
        return [
            'status' => ConnectorBuilderSessionStatus::class,
            'pending_credentials' => 'array',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class, 'client_dashboard_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ConnectorBuilderMessage::class)->orderBy('id');
    }

    public function blueprint(): HasOne
    {
        return $this->hasOne(ConnectorBlueprint::class);
    }
}
