<?php

namespace App\Services\Google;

use App\Enums\ConnectorType;
use Illuminate\Support\Facades\Crypt;
use RuntimeException;

class GoogleOAuthService
{
    public function __construct(protected GoogleTokenService $tokens) {}

    public function scopeFor(ConnectorType $type): string
    {
        return match ($type) {
            ConnectorType::SearchConsole => implode(' ', [
                'https://www.googleapis.com/auth/webmasters.readonly',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ]),
            ConnectorType::GoogleAnalytics => implode(' ', [
                'https://www.googleapis.com/auth/analytics.readonly',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ]),
            ConnectorType::GoogleAds => implode(' ', [
                'https://www.googleapis.com/auth/adwords',
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ]),
            default => throw new RuntimeException("Google OAuth is not configured for {$type->value}."),
        };
    }

    /**
     * @param  array{connector_type: string, dashboard_id: int, connection_id?: int|null, return_to: string, user_id: int}  $context
     */
    public function authorizationUrl(array $context): string
    {
        $this->ensureConfigured();

        $params = http_build_query([
            'client_id' => $this->tokens->clientId(),
            'redirect_uri' => $this->redirectUri(),
            'response_type' => 'code',
            'scope' => $this->scopeFor(ConnectorType::from($context['connector_type'])),
            'access_type' => 'offline',
            'prompt' => 'select_account consent',
            'state' => $this->encodeState($context),
        ]);

        return 'https://accounts.google.com/o/oauth2/v2/auth?'.$params;
    }

    /**
     * @return array{connector_type: string, dashboard_id: int, connection_id?: int|null, return_to: string, user_id: int}
     */
    public function decodeState(string $state): array
    {
        try {
            $payload = json_decode(Crypt::decryptString($state), true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            throw new RuntimeException('Invalid OAuth state.');
        }

        if (! is_array($payload)
            || ! isset($payload['connector_type'], $payload['dashboard_id'], $payload['return_to'], $payload['user_id'])
            || ! is_string($payload['connector_type'])
            || ! is_int($payload['dashboard_id'])
            || ! is_string($payload['return_to'])
            || ! is_int($payload['user_id'])) {
            throw new RuntimeException('Invalid OAuth state payload.');
        }

        if (isset($payload['expires_at']) && is_int($payload['expires_at']) && $payload['expires_at'] < now()->timestamp) {
            throw new RuntimeException('OAuth state has expired. Please try connecting again.');
        }

        return $payload;
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    public function exchangeCode(string $code): array
    {
        $this->ensureConfigured();

        return $this->tokens->exchangeAuthorizationCode($code, $this->redirectUri());
    }

    public function redirectUri(): string
    {
        return route('admin.google.oauth.callback', absolute: true);
    }

    /**
     * @param  array{connector_type: string, dashboard_id: int, connection_id?: int|null, return_to: string, user_id: int}  $context
     */
    protected function encodeState(array $context): string
    {
        $payload = [
            'connector_type' => $context['connector_type'],
            'dashboard_id' => $context['dashboard_id'],
            'connection_id' => $context['connection_id'] ?? null,
            'return_to' => $context['return_to'],
            'user_id' => $context['user_id'],
            'expires_at' => now()->addMinutes(15)->timestamp,
        ];

        return Crypt::encryptString(json_encode($payload, JSON_THROW_ON_ERROR));
    }

    protected function ensureConfigured(): void
    {
        if (! $this->tokens->isConfigured()) {
            throw new RuntimeException('Google OAuth is not configured. Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in the environment.');
        }
    }
}
