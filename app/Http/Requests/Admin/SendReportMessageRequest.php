<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendReportMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:4000'],
            'session_id' => ['nullable', 'integer', 'exists:analytics_report_sessions,id'],
            'preview_start' => ['nullable', 'date'],
            'preview_end' => ['nullable', 'date', 'after_or_equal:preview_start'],
        ];
    }
}
