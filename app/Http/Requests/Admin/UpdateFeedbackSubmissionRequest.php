<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateFeedbackSubmissionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'admin_notes' => ['nullable', 'string', 'max:5000'],
            'completion_message' => ['nullable', 'string', 'max:2000'],
            'mark_reviewed' => ['sometimes', 'boolean'],
            'mark_completed' => ['sometimes', 'boolean'],
        ];
    }
}
