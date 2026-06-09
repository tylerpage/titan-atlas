<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CoverPage extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'title',
        'period_start',
        'period_end',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function clientDashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(CoverPageBlock::class)->orderBy('sort_order');
    }
}
