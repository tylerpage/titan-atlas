<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SavedDashboard extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'title',
        'description',
        'created_by',
        'sort_order',
    ];

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class, 'client_dashboard_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(SavedDashboardBlock::class)->orderBy('sort_order');
    }
}
