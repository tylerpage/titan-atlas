<?php

namespace App\Http\Controllers\Admin;

use App\Enums\FeedbackStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateFeedbackSubmissionRequest;
use App\Models\FeedbackAttachment;
use App\Models\FeedbackSubmission;
use App\Services\Feedback\CompleteFeedbackSubmissionService;
use App\Services\Feedback\FeedbackAttachmentPresenter;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FeedbackSubmissionController extends Controller
{
    public function __construct(protected FeedbackAttachmentPresenter $attachments) {}
    public function index(): Response
    {
        return Inertia::render('Admin/Feedback/Index', [
            'submissions' => FeedbackSubmission::query()
                ->with(['user:id,name,email,role', 'clientDashboard:id,name,slug'])
                ->withCount('attachments')
                ->orderByRaw("CASE status WHEN 'pending' THEN 0 WHEN 'reviewed' THEN 1 ELSE 2 END")
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (FeedbackSubmission $submission) => $this->serializeListItem($submission)),
            'pending_count' => FeedbackSubmission::query()
                ->where('status', FeedbackStatus::Pending->value)
                ->count(),
        ]);
    }

    public function show(FeedbackSubmission $feedback): Response
    {
        $feedback->load([
            'user:id,name,email,role',
            'clientDashboard.company:id,name',
            'reviewedBy:id,name',
            'completedBy:id,name',
            'attachments',
        ]);

        return Inertia::render('Admin/Feedback/Show', [
            'submission' => $this->serializeDetail($feedback),
        ]);
    }

    public function update(
        UpdateFeedbackSubmissionRequest $request,
        FeedbackSubmission $feedback,
        CompleteFeedbackSubmissionService $completeFeedback,
    ): RedirectResponse {
        if ($request->boolean('mark_completed') && $feedback->status !== FeedbackStatus::Completed) {
            $completeFeedback->complete(
                $feedback,
                $request->user(),
                $request->validated('admin_notes'),
                $request->validated('completion_message'),
            );
        } elseif ($request->boolean('mark_reviewed') && $feedback->status === FeedbackStatus::Pending) {
            $feedback->markReviewed($request->user(), $request->validated('admin_notes'));
        } elseif ($request->has('admin_notes')) {
            $feedback->update(['admin_notes' => $request->validated('admin_notes')]);
        }

        $statusMessage = match (true) {
            $request->boolean('mark_completed') => 'Feedback marked complete and the user was notified by email.',
            $request->boolean('mark_reviewed') => 'Feedback marked reviewed. No email was sent.',
            default => 'Feedback updated.',
        };

        return redirect()
            ->route('admin.feedback.show', $feedback)
            ->with('status', $statusMessage);
    }

    public function downloadAttachment(FeedbackAttachment $attachment): StreamedResponse
    {
        $disk = $this->attachments->resolveReadableDisk($attachment);
        abort_unless($disk !== null, 404);

        return Storage::disk($disk)->download(
            $attachment->storage_path,
            $attachment->original_filename,
        );
    }

    public function showAttachment(FeedbackAttachment $attachment): StreamedResponse
    {
        abort_unless($this->attachments->isImage($attachment), 404);

        $disk = $this->attachments->resolveReadableDisk($attachment);
        abort_unless($disk !== null, 404);

        return Storage::disk($disk)->response(
            $attachment->storage_path,
            $attachment->original_filename,
            [
                'Content-Type' => $this->attachments->imageMimeType($attachment),
                'Content-Disposition' => 'inline; filename="'.addslashes($attachment->original_filename).'"',
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeListItem(FeedbackSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'reason' => $submission->reason->value,
            'reason_label' => $submission->reason->label(),
            'message_preview' => str($submission->message)->limit(120)->toString(),
            'status' => $submission->status->value,
            'status_label' => $submission->status->label(),
            'page_url' => $submission->page_url,
            'created_at' => $submission->created_at?->toIso8601String(),
            'user' => [
                'id' => $submission->user->id,
                'name' => $submission->user->name,
                'email' => $submission->user->email,
                'role' => $submission->user->role->value,
            ],
            'dashboard' => $submission->clientDashboard ? [
                'id' => $submission->clientDashboard->id,
                'name' => $submission->clientDashboard->name,
                'slug' => $submission->clientDashboard->slug,
            ] : null,
            'attachments_count' => $submission->attachments_count ?? $submission->attachments()->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeDetail(FeedbackSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'reason' => $submission->reason->value,
            'reason_label' => $submission->reason->label(),
            'message' => $submission->message,
            'status' => $submission->status->value,
            'status_label' => $submission->status->label(),
            'page_url' => $submission->page_url,
            'admin_notes' => $submission->admin_notes,
            'completion_message' => $submission->completion_message,
            'created_at' => $submission->created_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'completed_at' => $submission->completed_at?->toIso8601String(),
            'user' => [
                'id' => $submission->user->id,
                'name' => $submission->user->name,
                'email' => $submission->user->email,
                'role' => $submission->user->role->value,
            ],
            'dashboard' => $submission->clientDashboard ? [
                'id' => $submission->clientDashboard->id,
                'name' => $submission->clientDashboard->name,
                'slug' => $submission->clientDashboard->slug,
                'company_name' => $submission->clientDashboard->company?->name,
            ] : null,
            'reviewed_by' => $submission->reviewedBy ? [
                'id' => $submission->reviewedBy->id,
                'name' => $submission->reviewedBy->name,
            ] : null,
            'completed_by' => $submission->completedBy ? [
                'id' => $submission->completedBy->id,
                'name' => $submission->completedBy->name,
            ] : null,
            'attachments' => $submission->attachments
                ->map(fn (FeedbackAttachment $attachment) => $this->attachments->serialize($attachment))
                ->values()
                ->all(),
        ];
    }
}
