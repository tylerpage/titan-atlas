<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DashboardShareLink extends Model
{
    protected $fillable = [
        'code',
        'client_dashboard_id',
        'created_by_user_id',
        'query',
    ];

    protected function casts(): array
    {
        return [
            'query' => 'array',
        ];
    }

    public function clientDashboard(): BelongsTo
    {
        return $this->belongsTo(ClientDashboard::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function generateCode(): string
    {
        do {
            $code = Str::lower(Str::random(8));
        } while (self::query()->where('code', $code)->exists());

        return $code;
    }

    public function shortUrl(): string
    {
        return url('/s/'.$this->code);
    }
}
