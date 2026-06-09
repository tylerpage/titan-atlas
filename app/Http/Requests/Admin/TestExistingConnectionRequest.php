<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class TestExistingConnectionRequest extends FormRequest
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
            'credentials' => ['sometimes', 'array'],
            'credentials.*' => ['nullable', 'string', 'max:2048'],
        ];
    }
}
