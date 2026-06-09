<?php

namespace App\Models;

use App\Enums\ReportVisualizationType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsReport extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'analytics_report_session_id',
        'created_by',
        'prompt',
        'sql',
        'visualization_type',
        'visualization_config',
        'model',
        'archived_at',
    ];

    protected function casts(): array
    {
        return [
            'visualization_type' => ReportVisualizationType::class,
            'visualization_config' => 'array',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * @param  Builder<AnalyticsReport>  $query
     * @return Builder<AnalyticsReport>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<AnalyticsReport>  $query
     * @return Builder<AnalyticsReport>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function archive(): void
    {
        $this->update(['archived_at' => now()]);
    }

    public function restore(): void
    {
        $this->update(['archived_at' => null]);
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class, 'client_dashboard_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AnalyticsReportSession::class, 'analytics_report_session_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
