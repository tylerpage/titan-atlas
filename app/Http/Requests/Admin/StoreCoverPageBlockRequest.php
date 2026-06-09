<?php

namespace App\Http\Requests\Admin;

use App\Enums\CoverPageBlockType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCoverPageBlockRequest extends FormRequest
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
            'block_type' => ['required', Rule::enum(CoverPageBlockType::class)],
            'column_span' => ['sometimes', 'integer', 'in:1,2'],
            'configuration' => ['sometimes', 'array'],
        ];
    }
}
