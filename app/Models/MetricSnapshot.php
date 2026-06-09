<?php

namespace App\Models;

use App\Support\MetricDimensions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MetricSnapshot extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'snapshot_date',
        'metric_key',
        'dimension_hash',
        'metric_value',
        'currency',
        'dimensions',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'metric_value' => 'decimal:4',
            'dimensions' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (MetricSnapshot $snapshot): void {
            $snapshot->dimension_hash = MetricDimensions::hash($snapshot->dimensions);
        });
    }

    public function clientDashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class);
    }
}
