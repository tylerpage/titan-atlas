<?php

namespace App\Http\Requests\Client;

use App\Models\ClientDashboard;
use Illuminate\Foundation\Http\FormRequest;

class StoreSavedDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dashboard = $this->route('dashboard');

        return $dashboard instanceof ClientDashboard
            && ($this->user()?->canAccessDashboard($dashboard) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'analytics_report_id' => ['nullable', 'integer', 'exists:analytics_reports,id'],
            'block_title' => ['nullable', 'string', 'max:255'],
            'block_description' => ['nullable', 'string', 'max:2000'],
            'column_span' => ['nullable', 'integer', 'in:1,2'],
        ];
    }
}
