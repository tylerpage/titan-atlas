<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreClientDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->input('dashboard_template_id') === '' || $this->input('dashboard_template_id') === null) {
            $merge['dashboard_template_id'] = null;
        }

        if ($this->input('slug') === '') {
            $merge['slug'] = null;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'company_id' => ['required', 'integer', 'exists:companies,id'],
            'name' => ['required', 'string', 'max:255'],
            'slug' => [
                'nullable',
                'string',
                'max:255',
                'alpha_dash',
                Rule::unique('client_dashboards', 'slug')->where('company_id', $this->input('company_id')),
            ],
            'dashboard_template_id' => ['nullable', 'integer', 'exists:dashboard_templates,id'],
            'timezone' => ['nullable', 'string', 'max:64'],
            'currency' => ['nullable', 'string', 'in:USD'],
            'attribution_window_days' => ['nullable', 'integer', Rule::in(array_keys(config('titan.attribution_windows', [])))],
            'default_date_range' => ['nullable', 'string', Rule::in(array_keys(config('titan.date_range_presets', [])))],
        ];
    }
}
