<?php

namespace App\Http\Requests\Admin;

use App\Enums\ConnectorType;
use App\Http\Requests\Admin\Concerns\MergesGoogleOAuthCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TestConnectionRequest extends FormRequest
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
        $connectorType = ConnectorType::tryFrom((string) $this->input('connector_type'));
        $credentialRules = ['credentials' => ['required', 'array']];

        if ($connectorType) {
            foreach ($connectorType->credentialKeys() as $key) {
                if ($connectorType->usesGoogleOAuth() && in_array($key, $connectorType->oauthHiddenCredentialKeys(), true)) {
                    $credentialRules["credentials.{$key}"] = ['nullable', 'string', 'max:4096'];

                    continue;
                }

                $credentialRules["credentials.{$key}"] = ['required', 'string', 'max:2048'];
            }
        } else {
            $credentialRules['connector_type'] = ['required', Rule::enum(ConnectorType::class)];
        }

        return [
            'connector_type' => ['required', Rule::enum(ConnectorType::class)],
            'dashboard_id' => ['nullable', 'integer', 'exists:client_dashboards,id'],
            ...$credentialRules,
        ];
    }
}
