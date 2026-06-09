<?php

namespace App\Http\Requests\Client;

use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use Illuminate\Foundation\Http\FormRequest;

class PinReportFromChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        $dashboard = $this->route('dashboard');
        $report = $this->route('report');

        return $dashboard instanceof ClientDashboard
            && $report instanceof AnalyticsReport
            && $report->client_dashboard_id === $dashboard->id
            && ($this->user()?->canAccessDashboard($dashboard) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'saved_dashboard_id' => ['nullable', 'integer', 'exists:saved_dashboards,id'],
            'board_title' => ['required_without:saved_dashboard_id', 'string', 'max:255'],
            'board_description' => ['nullable', 'string', 'max:2000'],
            'title' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'column_span' => ['nullable', 'integer', 'in:1,2'],
        ];
    }
}
