<?php

namespace App\Models;

use App\Enums\ReportVisualizationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricDefinition extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'slug',
        'name',
        'description',
        'formula_notes',
        'sql_template',
        'visualization_type',
        'visualization_config',
        'connector_types',
        'created_by',
        'is_builtin',
    ];

    protected function casts(): array
    {
        return [
            'visualization_type' => ReportVisualizationType::class,
            'visualization_config' => 'array',
            'connector_types' => 'array',
            'is_builtin' => 'boolean',
        ];
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
