<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConnectorBlueprintDashboardVersion extends Model
{
    protected $fillable = [
        'connector_blueprint_id',
        'client_dashboard_id',
        'version_number',
        'dashboard_spec',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'dashboard_spec' => 'array',
        ];
    }

    public function blueprint(): BelongsTo
    {
        return $this->belongsTo(ConnectorBlueprint::class, 'connector_blueprint_id');
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class, 'client_dashboard_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
