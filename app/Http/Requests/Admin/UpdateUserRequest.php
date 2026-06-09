<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
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
        $userId = $this->route('user')?->id;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($userId)],
            'password' => ['nullable', 'string', Password::defaults(), 'confirmed'],
            'role' => ['required', Rule::enum(UserRole::class)],
            'company_ids' => ['nullable', 'array'],
            'company_ids.*' => ['integer', Rule::exists('companies', 'id')],
            'dashboard_ids' => ['nullable', 'array'],
            'dashboard_ids.*' => ['integer', Rule::exists('client_dashboards', 'id')],
        ];
    }
}
