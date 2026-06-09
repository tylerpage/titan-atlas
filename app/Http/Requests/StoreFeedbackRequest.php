<?php

namespace App\Http\Requests;

use App\Enums\FeedbackReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFeedbackRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $maxAttachments = max(0, (int) config('titan.feedback.max_attachments', 5));
        $maxKb = max(1, (int) config('titan.feedback.max_attachment_kb', 10240));

        return [
            'reason' => ['required', Rule::enum(FeedbackReason::class)],
            'message' => ['required', 'string', 'min:10', 'max:5000'],
            'page_url' => ['nullable', 'string', 'max:2048'],
            'client_dashboard_id' => ['nullable', 'integer', 'exists:client_dashboards,id'],
            'attachments' => ['nullable', 'array', 'max:'.$maxAttachments],
            'attachments.*' => ['file', 'max:'.$maxKb],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'message.min' => 'Please add a bit more detail so admins can help.',
            'attachments.max' => 'You can attach up to '.config('titan.feedback.max_attachments', 5).' files.',
        ];
    }
}
