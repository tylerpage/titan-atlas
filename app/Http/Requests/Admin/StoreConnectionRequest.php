<?php

namespace App\Http\Requests\Admin;

use App\Enums\ConnectorType;
use App\Http\Requests\Admin\Concerns\MergesGoogleOAuthCredentials;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreConnectionRequest extends FormRequest
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

                if (in_array($key, $connectorType->optionalCredentialKeys(), true)) {
                    $credentialRules["credentials.{$key}"] = ['nullable', 'string', 'max:2048'];

                    continue;
                }

                $credentialRules["credentials.{$key}"] = ['required', 'string', 'max:2048'];
            }
        } else {
            $credentialRules['connector_type'] = ['required', Rule::enum(ConnectorType::class)];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'connector_type' => ['required', Rule::enum(ConnectorType::class)],
            ...$credentialRules,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'credentials.*.required' => 'This credential field is required.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $connectorType = ConnectorType::tryFrom((string) $this->input('connector_type'));
            $credentials = $this->input('credentials', []);

            if (! is_array($credentials)) {
                return;
            }

            if ($connectorType?->usesGoogleOAuth() && empty($credentials['refresh_token'])) {
                $validator->errors()->add(
                    'credentials.refresh_token',
                    'Connect with Google before saving this connection.',
                );
            }

            if ($connectorType === ConnectorType::StackAdapt && empty($credentials['advertiser_id'])) {
                $validator->errors()->add(
                    'credentials.advertiser_id',
                    'Select a StackAdapt advertiser after testing your GraphQL API key.',
                );
            }

            if ($connectorType === ConnectorType::MetaAds && empty($credentials['ad_account_id'])) {
                $validator->errors()->add(
                    'credentials.ad_account_id',
                    'Select a Meta ad account after testing your access token.',
                );
            }

            if ($connectorType === ConnectorType::AmazonAds && empty($credentials['profile_id'])) {
                $validator->errors()->add(
                    'credentials.profile_id',
                    'Select an Amazon Ads profile after testing your access token.',
                );
            }

            if ($connectorType === ConnectorType::WalmartConnect && empty($credentials['advertiser_id'])) {
                $validator->errors()->add(
                    'credentials.advertiser_id',
                    'Select a Walmart Connect advertiser after testing your access token.',
                );
            }

            if ($connectorType === ConnectorType::EbayAds && empty($credentials['account_id'])) {
                $validator->errors()->add(
                    'credentials.account_id',
                    'Select an eBay ad account after testing your access token.',
                );
            }
        });
    }
}
