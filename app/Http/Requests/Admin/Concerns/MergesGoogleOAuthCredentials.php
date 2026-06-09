<?php

namespace App\Http\Requests\Admin\Concerns;

use App\Enums\ConnectorType;
use App\Services\Google\GoogleOAuthPendingSession;

trait MergesGoogleOAuthCredentials
{
    protected function prepareForValidation(): void
    {
        $connectorType = $this->resolveConnectorTypeForOAuth();

        if (! $connectorType?->usesGoogleOAuth()) {
            return;
        }

        $dashboardId = $this->resolveDashboardIdForOAuth();

        if ($dashboardId === null) {
            return;
        }

        $credentials = $this->input('credentials', []);

        if (! is_array($credentials)) {
            $credentials = [];
        }

        if (empty($credentials['refresh_token'])) {
            $token = app(GoogleOAuthPendingSession::class)
                ->refreshTokenFor($connectorType, $dashboardId);

            if ($token !== null) {
                $credentials['refresh_token'] = $token;
            }
        }

        $this->merge(['credentials' => $credentials]);
    }

    protected function resolveConnectorTypeForOAuth(): ?ConnectorType
    {
        if ($this->route('connection') instanceof \App\Models\Connection) {
            return $this->route('connection')->connector_type;
        }

        $type = $this->input('connector_type');

        return is_string($type) ? ConnectorType::tryFrom($type) : null;
    }

    protected function resolveDashboardIdForOAuth(): ?int
    {
        if ($this->route('dashboard') instanceof \App\Models\ClientDashboard) {
            return $this->route('dashboard')->id;
        }

        if ($this->route('connection') instanceof \App\Models\Connection) {
            return $this->route('connection')->client_dashboard_id;
        }

        $dashboardId = $this->input('dashboard_id');

        return is_numeric($dashboardId) ? (int) $dashboardId : null;
    }
}
