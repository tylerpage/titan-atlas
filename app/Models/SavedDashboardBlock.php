<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SavedDashboardBlock extends Model
{
    protected $fillable = [
        'saved_dashboard_id',
        'analytics_report_id',
        'title',
        'description',
        'column_span',
        'sort_order',
    ];

    public function savedDashboard(): BelongsTo
    {
        return $this->belongsTo(SavedDashboard::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(AnalyticsReport::class, 'analytics_report_id');
    }
}
