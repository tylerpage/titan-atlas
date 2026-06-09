<?php

namespace App\Http\Requests\Admin;

use App\Enums\ConnectorType;
use App\Http\Requests\Admin\Concerns\MergesGoogleOAuthCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateConnectionRequest extends FormRequest
{
    use MergesGoogleOAuthCredentials;
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var \App\Models\Connection|null $connection */
        $connection = $this->route('connection');
        $connectorType = $connection?->connector_type;
        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'credentials' => ['nullable', 'array'],
        ];

        if ($connectorType instanceof ConnectorType) {
            foreach ($connectorType->credentialKeys() as $key) {
                $max = in_array($key, $connectorType->oauthHiddenCredentialKeys(), true) ? 4096 : 2048;
                $rules["credentials.{$key}"] = ['nullable', 'string', "max:{$max}"];
            }
        }

        return $rules;
    }
}
