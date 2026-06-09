<?php

namespace App\Services\Feedback;

use App\Enums\FeedbackReason;
use App\Enums\FeedbackStatus;
use App\Models\ClientDashboard;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackSubmission;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class SubmitFeedbackService
{
    /**
     * @param  array{reason: string, message: string, page_url?: string|null, client_dashboard_id?: int|null}  $data
     * @param  list<UploadedFile>  $attachments
     */
    public function submit(User $user, array $data, array $attachments = []): FeedbackSubmission
    {
        $dashboardId = isset($data['client_dashboard_id']) ? (int) $data['client_dashboard_id'] : null;

        if ($dashboardId) {
            $dashboard = ClientDashboard::query()->find($dashboardId);
            abort_unless($dashboard && $user->canAccessDashboard($dashboard), 403);
        }

        $submission = FeedbackSubmission::query()->create([
            'user_id' => $user->id,
            'client_dashboard_id' => $dashboardId,
            'reason' => FeedbackReason::from($data['reason']),
            'message' => trim($data['message']),
            'page_url' => $data['page_url'] ?? null,
            'status' => FeedbackStatus::Pending,
        ]);

        foreach ($attachments as $file) {
            $this->storeAttachment($submission, $file);
        }

        return $submission->load(['user', 'clientDashboard', 'attachments']);
    }

    protected function storeAttachment(FeedbackSubmission $submission, UploadedFile $file): FeedbackAttachment
    {
        $path = $file->store('feedback/'.$submission->id, 'local');

        return FeedbackAttachment::query()->create([
            'feedback_submission_id' => $submission->id,
            'original_filename' => $file->getClientOriginalName(),
            'storage_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size_bytes' => $file->getSize() ?: 0,
        ]);
    }

    public function deleteAttachmentFile(FeedbackAttachment $attachment): void
    {
        if (Storage::disk('local')->exists($attachment->storage_path)) {
            Storage::disk('local')->delete($attachment->storage_path);
        }
    }
}
