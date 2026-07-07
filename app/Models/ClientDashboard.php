<?php

namespace App\Models;

use App\Enums\SyncStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ClientDashboard extends Model
{
    protected $fillable = [
        'company_id',
        'dashboard_template_id',
        'name',
        'slug',
        'logo_path',
        'primary_color',
        'secondary_color',
        'powered_by_text',
        'show_powered_by',
        'timezone',
        'currency',
        'default_date_range',
        'show_summary_tab',
        'attribution_window_days',
        'custom_domain',
    ];

    protected function casts(): array
    {
        return [
            'show_powered_by' => 'boolean',
            'show_summary_tab' => 'boolean',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DashboardTemplate::class, 'dashboard_template_id');
    }

    public function connections(): HasMany
    {
        return $this->hasMany(Connection::class);
    }

    public function widgetPlacements(): HasMany
    {
        return $this->hasMany(WidgetPlacement::class)->orderBy('sort_order');
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'client_dashboard_user')
            ->withTimestamps();
    }

    public function metricSnapshots(): HasMany
    {
        return $this->hasMany(MetricSnapshot::class);
    }

    public function coverPages(): HasMany
    {
        return $this->hasMany(CoverPage::class)->orderByDesc('sort_order');
    }

    public function activeCoverPage(): ?CoverPage
    {
        return $this->coverPages()->where('is_draft', false)->where('is_active', true)->first();
    }

    public function hasPublishedCoverPages(): bool
    {
        if ($this->relationLoaded('coverPages')) {
            return $this->coverPages->contains(fn (CoverPage $page) => ! $page->is_draft);
        }

        return $this->coverPages()->where('is_draft', false)->exists();
    }

    public function showsSummaryTab(): bool
    {
        return $this->show_summary_tab && $this->hasPublishedCoverPages();
    }

    public function analyticsReports(): HasMany
    {
        return $this->hasMany(AnalyticsReport::class)->latest();
    }

    public function analyticsReportSessions(): HasMany
    {
        return $this->hasMany(AnalyticsReportSession::class)->latest();
    }

    public function savedDashboards(): HasMany
    {
        return $this->hasMany(SavedDashboard::class)->orderByDesc('sort_order');
    }

    public function isSyncing(): bool
    {
        if ($this->relationLoaded('connections')) {
            return $this->connections->contains(
                fn (Connection $connection) => $connection->sync_status === SyncStatus::Running,
            );
        }

        return $this->connections()->where('sync_status', SyncStatus::Running)->exists();
    }
}
