<?php

namespace App\Services\Google;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\GoogleAds\GoogleAdsApiClient;
use App\Ingestion\Connectors\GoogleAnalytics\GoogleAnalyticsAdminClient;
use App\Ingestion\Connectors\SearchConsole\SearchConsoleClient;
use App\Models\GoogleOAuthPending;
use Illuminate\Support\Facades\Auth;

class GoogleOAuthPendingSession
{
    public const FLASH_KEY = 'google_oauth';

    /**
     * @param  list<array<string, mixed>>  $oauthOptions
     */
    public function store(
        ConnectorType $connectorType,
        int $dashboardId,
        string $refreshToken,
        array $oauthOptions,
        ?int $connectionId = null,
        ?int $userId = null,
        ?string $googleEmail = null,
        ?string $googleName = null,
    ): void {
        $userId ??= Auth::id();

        if ($userId === null) {
            throw new \RuntimeException('Google OAuth pending state requires an authenticated user.');
        }

        $expiresAt = now()->addMinutes((int) config('titan.google.oauth_pending_ttl_minutes', 30));

        GoogleOAuthPending::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'client_dashboard_id' => $dashboardId,
                'connector_type' => $connectorType->value,
            ],
            [
                'refresh_token' => $refreshToken,
                'google_email' => $googleEmail,
                'google_name' => $googleName,
                'sites' => $oauthOptions,
                'connection_id' => $connectionId,
                'expires_at' => $expiresAt,
            ],
        );
    }

    public function forget(?int $dashboardId = null, ?ConnectorType $connectorType = null): void
    {
        if (Auth::id() === null) {
            return;
        }

        $query = GoogleOAuthPending::query();

        if (! Auth::user()?->isAdmin()) {
            $query->where('user_id', Auth::id());
        }

        if ($dashboardId !== null) {
            $query->where('client_dashboard_id', $dashboardId);
        }

        if ($connectorType !== null) {
            $query->where('connector_type', $connectorType->value);
        }

        $query->delete();
    }

    public function refreshTokenFor(ConnectorType $connectorType, int $dashboardId): ?string
    {
        $pending = $this->findPending($connectorType, $dashboardId);

        if ($pending === null) {
            return null;
        }

        return $pending->refresh_token;
    }

    /**
     * @return array<string, mixed>
     */
    public function propsForDashboard(ConnectorType $connectorType, int $dashboardId): array
    {
        $flashed = session(self::FLASH_KEY);

        if (is_array($flashed) && ($flashed['connected'] ?? false) && ($flashed['connector_type'] ?? null) === $connectorType->value) {
            return $flashed;
        }

        return $this->toInertiaProps($connectorType, $dashboardId);
    }

    /**
     * @return array<string, mixed>
     */
    public function propsForDashboardDefault(int $dashboardId): array
    {
        $connectorType = $this->defaultConnectorTypeForDashboard($dashboardId);

        if ($connectorType === null) {
            return $this->emptyProps();
        }

        return $this->propsForDashboard(ConnectorType::from($connectorType), $dashboardId);
    }

    /**
     * @return array<string, mixed>
     */
    public function toInertiaProps(ConnectorType $connectorType, int $dashboardId): array
    {
        $pending = $this->findPending($connectorType, $dashboardId);

        if ($pending === null) {
            return $this->emptyProps();
        }

        $options = is_array($pending->sites) ? $pending->sites : [];
        $options = $this->refreshOAuthOptions($connectorType, $pending, $options);

        return $this->flashProps(
            connectorType: $connectorType,
            oauthOptions: $options,
            googleEmail: $pending->google_email,
            googleName: $pending->google_name,
        );
    }

    public function defaultConnectorTypeForDashboard(int $dashboardId): ?string
    {
        $flashed = session(self::FLASH_KEY);

        if (is_array($flashed) && ($flashed['connected'] ?? false)) {
            $connectorType = $flashed['connector_type'] ?? null;

            return is_string($connectorType) && $connectorType !== '' ? $connectorType : null;
        }

        $pending = $this->pendingQueryForDashboard($dashboardId)
            ->orderByDesc('id')
            ->value('connector_type');

        return is_string($pending) && $pending !== '' ? $pending : null;
    }

    /**
     * @param  list<array<string, mixed>>  $oauthOptions
     * @return array<string, mixed>
     */
    public function flashProps(
        ConnectorType $connectorType,
        array $oauthOptions,
        ?string $googleEmail = null,
        ?string $googleName = null,
    ): array {
        $props = [
            'connected' => true,
            'connector_type' => $connectorType->value,
            'google_email' => $googleEmail,
            'google_name' => $googleName,
            'sites' => [],
            'properties' => [],
            'customers' => [],
        ];

        if ($connectorType === ConnectorType::SearchConsole) {
            $props['sites'] = $oauthOptions;
        }

        if ($connectorType === ConnectorType::GoogleAnalytics) {
            $props['properties'] = $oauthOptions;
        }

        if ($connectorType === ConnectorType::GoogleAds) {
            $props['customers'] = $oauthOptions;
        }

        return $props;
    }

    /**
     * @param  list<array<string, mixed>>  $fallback
     * @return list<array<string, mixed>>
     */
    protected function refreshOAuthOptions(ConnectorType $connectorType, GoogleOAuthPending $pending, array $fallback): array
    {
        try {
            $options = match ($connectorType) {
                ConnectorType::SearchConsole => app(SearchConsoleClient::class)->listSites($pending->refresh_token),
                ConnectorType::GoogleAnalytics => app(GoogleAnalyticsAdminClient::class)->listProperties($pending->refresh_token),
                ConnectorType::GoogleAds => app(GoogleAdsApiClient::class)->listSelectableCustomers($pending->refresh_token),
                default => [],
            };
        } catch (\Throwable) {
            return $fallback;
        }

        if ($options === []) {
            return $fallback;
        }

        $pending->update(['sites' => $options]);

        return $options;
    }

    protected function findPending(ConnectorType $connectorType, int $dashboardId): ?GoogleOAuthPending
    {
        if (Auth::id() === null) {
            return null;
        }

        $pending = $this->pendingQueryForDashboard($dashboardId)
            ->where('connector_type', $connectorType->value)
            ->orderByDesc('id')
            ->first();

        if ($pending === null) {
            return null;
        }

        if ($pending->expires_at !== null && $pending->expires_at->isPast()) {
            $pending->delete();

            return null;
        }

        return $pending;
    }

    protected function pendingQueryForDashboard(int $dashboardId)
    {
        $query = GoogleOAuthPending::query()
            ->where('client_dashboard_id', $dashboardId)
            ->where('expires_at', '>', now());

        if (! Auth::user()?->isAdmin()) {
            $query->where('user_id', Auth::id());
        }

        return $query;
    }

    /**
     * @return array<string, mixed>
     */
    protected function emptyProps(): array
    {
        return [
            'connected' => false,
            'connector_type' => null,
            'google_email' => null,
            'google_name' => null,
            'sites' => [],
            'properties' => [],
            'customers' => [],
        ];
    }
}
