<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DashboardTemplate extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'default_widgets',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'default_widgets' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function clientDashboards(): HasMany
    {
        return $this->hasMany(ClientDashboard::class);
    }
}
