<?php

namespace App\Models;

use App\Enums\AnalyticsReportSessionStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class AnalyticsReportSession extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'user_id',
        'status',
        'title',
    ];

    protected function casts(): array
    {
        return [
            'status' => AnalyticsReportSessionStatus::class,
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
        return $this->hasMany(AnalyticsReportMessage::class)->orderBy('id');
    }

    public function report(): HasOne
    {
        return $this->hasOne(AnalyticsReport::class);
    }
}
