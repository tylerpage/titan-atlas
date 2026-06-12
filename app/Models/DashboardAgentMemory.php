<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DashboardAgentMemory extends Model
{
    protected $fillable = [
        'client_dashboard_id',
        'memory_key',
        'category',
        'agent_flow',
        'title',
        'content',
        'source_tool',
        'created_by',
        'last_used_at',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'last_used_at' => 'datetime',
        ];
    }

    public function dashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class, 'client_dashboard_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
