<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeedbackAttachment extends Model
{
    protected $fillable = [
        'feedback_submission_id',
        'original_filename',
        'storage_path',
        'mime_type',
        'size_bytes',
    ];

    public function submission(): BelongsTo
    {
        return $this->belongsTo(FeedbackSubmission::class, 'feedback_submission_id');
    }
}
