<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ImportAiConnectorRequest extends FormRequest
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
            'payload' => ['required_without:file', 'nullable', 'string'],
            'file' => ['required_without:payload', 'nullable', 'file', 'mimes:json,txt', 'max:512'],
            'scope' => ['required', Rule::in(['global', 'company'])],
            'mode' => ['required', Rule::in(['create', 'replace'])],
            'company_id' => ['nullable', 'required_if:scope,company', Rule::exists('companies', 'id')],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'payload.required_without' => 'Paste export JSON or choose a file to import.',
            'file.required_without' => 'Paste export JSON or choose a file to import.',
        ];
    }
}
