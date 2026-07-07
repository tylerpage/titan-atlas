<?php

namespace App\Services\Feedback;

use App\Enums\FeedbackStatus;
use App\Mail\FeedbackCompletedMail;
use App\Models\FeedbackSubmission;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

class CompleteFeedbackSubmissionService
{
    public function complete(
        FeedbackSubmission $submission,
        User $admin,
        ?string $adminNotes = null,
        ?string $completionMessage = null,
    ): FeedbackSubmission {
        if ($submission->status === FeedbackStatus::Completed) {
            return $submission;
        }

        $submission->loadMissing('user');

        $attributes = [
            'status' => FeedbackStatus::Completed,
            'completed_at' => now(),
            'completed_by_user_id' => $admin->id,
            'admin_notes' => $adminNotes ?? $submission->admin_notes,
            'completion_message' => $completionMessage !== null && trim($completionMessage) !== ''
                ? trim($completionMessage)
                : null,
        ];

        if ($submission->reviewed_at === null) {
            $attributes['reviewed_at'] = now();
            $attributes['reviewed_by_user_id'] = $admin->id;
        }

        $submission->fill($attributes)->save();

        Mail::to($submission->user->email)->send(new FeedbackCompletedMail($submission->fresh()));

        return $submission->fresh();
    }
}
