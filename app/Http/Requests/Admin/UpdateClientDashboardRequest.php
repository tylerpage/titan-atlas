<?php

namespace App\Http\Requests\Admin;

use App\Models\ClientDashboard;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateClientDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        if ($this->boolean('remove_logo')) {
            $this->merge(['remove_logo' => true]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var ClientDashboard $dashboard */
        $dashboard = $this->route('dashboard');

        return [
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'required',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('client_dashboards', 'slug')
                    ->where('company_id', $dashboard->company_id)
                    ->ignore($dashboard->id),
            ],
            'timezone' => ['nullable', 'string', 'max:64'],
            'default_date_range' => ['nullable', 'string', Rule::in(array_keys(config('titan.date_range_presets', [])))],
            'attribution_window_days' => ['nullable', 'integer', Rule::in(array_keys(config('titan.attribution_windows', [])))],
            'primary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'secondary_color' => ['nullable', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'custom_domain' => [
                'nullable',
                'string',
                'max:255',
                Rule::unique('client_dashboards', 'custom_domain')->ignore($dashboard->id),
            ],
            'show_summary_tab' => ['sometimes', 'boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
            'remove_logo' => ['sometimes', 'boolean'],
        ];
    }
}
