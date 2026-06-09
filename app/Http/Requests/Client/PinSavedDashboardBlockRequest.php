<?php

namespace App\Http\Requests\Client;

use App\Models\SavedDashboard;
use Illuminate\Foundation\Http\FormRequest;

class PinSavedDashboardBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $board = $this->route('board');

        return $board instanceof SavedDashboard
            && ($this->user()?->canAccessDashboard($board->dashboard) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'analytics_report_id' => ['required', 'integer', 'exists:analytics_reports,id'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'column_span' => ['nullable', 'integer', 'in:1,2'],
        ];
    }
}
