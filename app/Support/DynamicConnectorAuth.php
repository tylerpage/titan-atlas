<?php

namespace App\Support;

use InvalidArgumentException;

class DynamicConnectorAuth
{
    /**
     * @param  array<string, mixed>|null  $authConfig
     * @return array<string, mixed>|null
     */
    public static function normalize(?array $authConfig): ?array
    {
        if ($authConfig === null || $authConfig === []) {
            return $authConfig;
        }

        $type = (string) ($authConfig['type'] ?? 'api_key');

        if ($type !== 'oauth2_client_credentials') {
            return $authConfig;
        }

        $clientIdKey = (string) ($authConfig['client_id_key'] ?? 'client_id');
        $clientSecretKey = (string) ($authConfig['client_secret_key'] ?? 'client_secret');
        $grantType = (string) ($authConfig['grant_type'] ?? 'client_credentials');
        $tokenPath = (string) ($authConfig['token_path'] ?? 'access_token');
        $tokenEndpoint = (string) ($authConfig['token_url'] ?? $authConfig['token_endpoint'] ?? '/oauth/token');
        $bodyFormat = (string) ($authConfig['body_format'] ?? 'form');
        $clientAuth = (string) ($authConfig['client_auth'] ?? 'body');

        $body = is_array($authConfig['token_request']['body'] ?? null)
            ? $authConfig['token_request']['body']
            : [
                'grant_type' => $grantType,
            ];

        if ($clientAuth === 'body') {
            $body['client_id'] = $body['client_id'] ?? '{{'.$clientIdKey.'}}';
            $body['client_secret'] = $body['client_secret'] ?? '{{'.$clientSecretKey.'}}';
        }

        if (isset($authConfig['scope']) && is_string($authConfig['scope']) && $authConfig['scope'] !== '') {
            $body['scope'] = $authConfig['scope'];
        }

        $tokenRequest = array_merge([
            'method' => 'POST',
            'path' => $tokenEndpoint,
            'body_format' => $bodyFormat,
            'client_auth' => $clientAuth,
            'body' => $body,
            'token_path' => $tokenPath,
            'expires_in_path' => $authConfig['expires_in_path'] ?? 'expires_in',
        ], is_array($authConfig['token_request'] ?? null) ? $authConfig['token_request'] : []);

        $tokenRequest['method'] = app(DynamicConnectorReadOnlyGuard::class)
            ->normalizeHttpMethod('POST');

        return [
            'type' => 'oauth2_client_credentials',
            'client_id_key' => $clientIdKey,
            'client_secret_key' => $clientSecretKey,
            'grant_type' => $grantType,
            'token_url' => $tokenEndpoint,
            'body_format' => $bodyFormat,
            'client_auth' => $clientAuth,
            'token_path' => $tokenPath,
            'token_request' => $tokenRequest,
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $credentialSchema
     * @param  array<string, mixed>|null  $authConfig
     * @return list<array<string, mixed>>|null
     */
    public static function normalizeCredentialSchema(?array $credentialSchema, ?array $authConfig): ?array
    {
        if ($credentialSchema !== null && $credentialSchema !== []) {
            return $credentialSchema;
        }

        $authConfig = self::normalize($authConfig);

        if (($authConfig['type'] ?? null) !== 'oauth2_client_credentials') {
            return $credentialSchema;
        }

        return [
            [
                'key' => $authConfig['client_id_key'] ?? 'client_id',
                'label' => 'Client ID',
                'type' => 'text',
            ],
            [
                'key' => $authConfig['client_secret_key'] ?? 'client_secret',
                'label' => 'Client Secret',
                'type' => 'password',
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function allowedTypes(): array
    {
        return config('titan.connector_builder.allowed_auth_types', [
            'api_key',
            'bearer',
            'basic',
            'oauth2_client_credentials',
        ]);
    }

    public static function assertAllowedType(string $type): void
    {
        if (! in_array($type, self::allowedTypes(), true)) {
            throw new InvalidArgumentException(
                "Auth type [{$type}] is not allowed. Allowed types: ".implode(', ', self::allowedTypes()).'.',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    public static function usesTokenRequest(array $auth): bool
    {
        return is_array($auth['token_request'] ?? null)
            || ($auth['type'] ?? null) === 'oauth2_client_credentials';
    }

    /**
     * @param  array<string, mixed>  $auth
     * @return array<string, mixed>
     */
    public static function tokenRequest(array $auth): array
    {
        $normalized = self::normalize($auth) ?? $auth;
        $tokenRequest = $normalized['token_request'] ?? null;

        if (! is_array($tokenRequest)) {
            throw new InvalidArgumentException('Auth config is missing token_request settings.');
        }

        return self::finalizeTokenRequest($tokenRequest);
    }

    /**
     * OAuth token endpoints must use POST. Some blueprints may incorrectly store GET.
     *
     * @param  array<string, mixed>  $tokenRequest
     * @return array<string, mixed>
     */
    public static function finalizeTokenRequest(array $tokenRequest): array
    {
        $tokenRequest['method'] = 'POST';

        if (! isset($tokenRequest['body_format']) || ! is_string($tokenRequest['body_format'])) {
            $tokenRequest['body_format'] = 'form';
        }

        if (! isset($tokenRequest['path']) || ! is_string($tokenRequest['path']) || trim($tokenRequest['path']) === '') {
            $tokenRequest['path'] = '/oauth/token';
        }

        return $tokenRequest;
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    public static function tokenRequestPath(array $auth): ?string
    {
        if (! self::usesTokenRequest($auth)) {
            return null;
        }

        try {
            $path = (string) (self::tokenRequest($auth)['path'] ?? '');

            return $path !== '' ? $path : null;
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>|null  $authConfig
     */
    public static function testEndpointConflictsWithTokenRequest(?array $authConfig, ?string $testEndpoint): bool
    {
        if ($testEndpoint === null || trim($testEndpoint) === '' || ! is_array($authConfig)) {
            return false;
        }

        $tokenPath = self::tokenRequestPath($authConfig);

        if ($tokenPath === null) {
            return false;
        }

        return self::pathsMatch($tokenPath, $testEndpoint);
    }

    public static function pathsMatch(string $left, string $right): bool
    {
        $normalize = static function (string $path): string {
            $pathOnly = parse_url($path, PHP_URL_PATH) ?? $path;

            return strtolower(rtrim($pathOnly, '/'));
        };

        return $normalize($left) === $normalize($right);
    }

    public static function agentOAuthGuidance(): string
    {
        return <<<'GUIDANCE'
OAuth2 client-credentials (supported):
- Use auth_config.type = "oauth2_client_credentials"
- Ask the user for client_id and client_secret only — do NOT ask for a pre-issued access token
- Configure token_url to the provider's token endpoint (e.g. Shopware: /api/oauth/token)
- sync_config.test_endpoint must be a read-only data endpoint (e.g. /api/order?limit=1), never the OAuth token URL
- Example auth_config:
  {
    "type": "oauth2_client_credentials",
    "token_url": "/api/oauth/token",
    "grant_type": "client_credentials",
    "client_id_key": "client_id",
    "client_secret_key": "client_secret",
    "body_format": "form",
    "client_auth": "body"
  }
- credential_schema: [{ key: client_id, label: Client ID, type: text }, { key: client_secret, label: Client Secret, type: password }]
- Alternatively use auth_config.type = "bearer" with an explicit token_request block for non-standard token endpoints

OAuth2 authorization-code / user-consent flows (NOT supported — record dev tasks):
- Browser redirects, refresh tokens managed by a human login, or Shopify-style app installs
GUIDANCE;
    }
}
