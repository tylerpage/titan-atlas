<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AnalyticsReportMessage extends Model
{
    protected $fillable = [
        'analytics_report_session_id',
        'role',
        'content',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AnalyticsReportSession::class, 'analytics_report_session_id');
    }
}
