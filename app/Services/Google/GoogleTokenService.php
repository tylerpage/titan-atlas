<?php

namespace App\Services\Google;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class GoogleTokenService
{
    protected string $tokenUrl = 'https://oauth2.googleapis.com/token';

    public function refreshAccessToken(string $refreshToken): string
    {
        $response = $this->tokenRequest([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google token refresh did not return an access token.');
        }

        return $accessToken;
    }

    /**
     * @return array{access_token: string, refresh_token: string|null, expires_in: int|null}
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        $response = $this->tokenRequest([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $this->clientId(),
            'client_secret' => $this->clientSecret(),
        ]);

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Google authorization code exchange did not return an access token.');
        }

        return [
            'access_token' => $accessToken,
            'refresh_token' => $response->json('refresh_token'),
            'expires_in' => $response->json('expires_in'),
        ];
    }

    public function clientId(): string
    {
        return (string) config('titan.google.client_id', '');
    }

    public function clientSecret(): string
    {
        return (string) config('titan.google.client_secret', '');
    }

    public function isConfigured(): bool
    {
        return $this->clientId() !== '' && $this->clientSecret() !== '';
    }

    /**
     * @param  array<string, string>  $payload
     */
    protected function tokenRequest(array $payload): Response
    {
        $response = Http::asForm()->post($this->tokenUrl, $payload);

        if (! $response->successful()) {
            $message = $response->json('error_description') ?? $response->json('error') ?? $response->body();

            throw new RuntimeException('Google OAuth token request failed: '.$message);
        }

        return $response;
    }
}
