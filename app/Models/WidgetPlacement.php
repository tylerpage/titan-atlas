<?php

namespace App\Models;

use App\Enums\WidgetType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WidgetPlacement extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'widget_type',
        'title',
        'sort_order',
        'column_span',
        'configuration',
        'is_visible',
    ];

    protected function casts(): array
    {
        return [
            'widget_type' => WidgetType::class,
            'configuration' => 'array',
            'is_visible' => 'boolean',
        ];
    }

    public function clientDashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class);
    }
}
