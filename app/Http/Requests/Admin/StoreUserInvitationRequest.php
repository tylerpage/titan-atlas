<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use App\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreUserInvitationRequest extends FormRequest
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
        /** @var Company $company */
        $company = $this->route('company');

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'dashboard_ids' => ['nullable', 'array'],
            'dashboard_ids.*' => [
                'integer',
                Rule::exists('client_dashboards', 'id')->where('company_id', $company->id),
            ],
        ];
    }
}
