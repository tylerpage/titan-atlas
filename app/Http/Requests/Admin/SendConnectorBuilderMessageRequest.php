<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class SendConnectorBuilderMessageRequest extends FormRequest
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
            'message' => ['required', 'string', 'max:4000'],
            'session_id' => ['nullable', 'integer', 'exists:connector_builder_sessions,id'],
            'credentials' => ['nullable', 'array'],
            'session_config' => ['nullable', 'array'],
            'session_config.base_url' => ['nullable', 'string', 'url', 'max:500'],
        ];
    }
}
