<?php

namespace App\Services\Google;

use App\Enums\ConnectorType;

class GoogleOAuthPendingSession
{
    protected const SESSION_KEY = 'google_oauth_pending';

    /**
     * @param  list<array{siteUrl: string, permissionLevel: string}>  $sites
     */
    public function store(
        ConnectorType $connectorType,
        int $dashboardId,
        string $refreshToken,
        array $sites,
        ?int $connectionId = null,
    ): void {
        session()->put(self::SESSION_KEY, [
            'connector_type' => $connectorType->value,
            'dashboard_id' => $dashboardId,
            'connection_id' => $connectionId,
            'refresh_token' => $refreshToken,
            'sites' => $sites,
            'expires_at' => now()
                ->addMinutes((int) config('titan.google.oauth_pending_ttl_minutes', 30))
                ->toIso8601String(),
        ]);
    }

    public function forget(): void
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function get(): ?array
    {
        $pending = session(self::SESSION_KEY);

        if (! is_array($pending)) {
            return null;
        }

        if (isset($pending['expires_at']) && is_string($pending['expires_at'])) {
            if (now()->greaterThan($pending['expires_at'])) {
                $this->forget();

                return null;
            }
        }

        return $pending;
    }

    public function refreshTokenFor(ConnectorType $connectorType, int $dashboardId): ?string
    {
        $pending = $this->get();

        if ($pending === null) {
            return null;
        }

        if (($pending['connector_type'] ?? null) !== $connectorType->value) {
            return null;
        }

        if ((int) ($pending['dashboard_id'] ?? 0) !== $dashboardId) {
            return null;
        }

        $token = $pending['refresh_token'] ?? null;

        return is_string($token) && $token !== '' ? $token : null;
    }

    /**
     * @return array{connected: bool, sites: list<array{siteUrl: string, permissionLevel: string}>}
     */
    public function toInertiaProps(ConnectorType $connectorType, int $dashboardId): array
    {
        $pending = $this->get();

        if ($pending === null
            || ($pending['connector_type'] ?? null) !== $connectorType->value
            || (int) ($pending['dashboard_id'] ?? 0) !== $dashboardId) {
            return [
                'connected' => false,
                'sites' => [],
            ];
        }

        $sites = $pending['sites'] ?? [];

        return [
            'connected' => is_string($pending['refresh_token'] ?? null) && $pending['refresh_token'] !== '',
            'sites' => is_array($sites) ? $sites : [],
        ];
    }
}
