<?php

namespace App\Http\Requests\Admin;

use App\Enums\ConnectorBlueprintStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAiConnectorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'label' => ['required', 'string', 'max:255'],
            'slug' => ['required', 'string', 'max:255', 'alpha_dash'],
            'status' => ['required', Rule::enum(ConnectorBlueprintStatus::class)],
        ];
    }
}
