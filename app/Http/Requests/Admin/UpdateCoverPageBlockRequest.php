<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateCoverPageBlockRequest extends FormRequest
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
            'column_span' => ['sometimes', 'integer', 'in:1,2'],
            'configuration' => ['sometimes', 'array'],
        ];
    }
}
