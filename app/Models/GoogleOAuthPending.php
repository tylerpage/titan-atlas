<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoogleOAuthPending extends Model
{
    protected $table = 'google_oauth_pendings';

    protected $fillable = [
        'user_id',
        'client_dashboard_id',
        'connector_type',
        'refresh_token',
        'google_email',
        'google_name',
        'sites',
        'connection_id',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'refresh_token' => 'encrypted',
            'sites' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function clientDashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class);
    }
}
