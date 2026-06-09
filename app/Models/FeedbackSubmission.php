<?php

namespace App\Models;

use App\Enums\FeedbackReason;
use App\Enums\FeedbackStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FeedbackSubmission extends Model
{
    protected $fillable = [
        'user_id',
        'client_dashboard_id',
        'reason',
        'message',
        'page_url',
        'status',
        'reviewed_at',
        'reviewed_by_user_id',
        'admin_notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reason' => FeedbackReason::class,
            'status' => FeedbackStatus::class,
            'reviewed_at' => 'datetime',
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

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_user_id');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(FeedbackAttachment::class);
    }

    public function markReviewed(User $admin, ?string $adminNotes = null): void
    {
        $this->fill([
            'status' => FeedbackStatus::Reviewed,
            'reviewed_at' => now(),
            'reviewed_by_user_id' => $admin->id,
            'admin_notes' => $adminNotes ?? $this->admin_notes,
        ]);
        $this->save();
    }
}
