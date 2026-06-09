<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConnectorType;
use App\Http\Controllers\Controller;
use App\Ingestion\Connectors\GoogleAds\GoogleAdsApiClient;
use App\Ingestion\Connectors\GoogleAnalytics\GoogleAnalyticsAdminClient;
use App\Ingestion\Connectors\SearchConsole\SearchConsoleClient;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Services\Google\GoogleOAuthPendingSession;
use App\Services\Google\GoogleOAuthService;
use App\Services\Google\GoogleTokenService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GoogleOAuthController extends Controller
{
    public function redirect(Request $request, GoogleOAuthService $oauth): RedirectResponse
    {
        $validated = $request->validate([
            'connector_type' => ['required', 'in:search_console,google_analytics,google_ads'],
            'dashboard_id' => ['required', 'integer', 'exists:client_dashboards,id'],
            'connection_id' => ['nullable', 'integer', 'exists:connections,id'],
            'return_to' => ['required', 'in:create,edit'],
        ]);

        $connectorType = ConnectorType::from($validated['connector_type']);
        ClientDashboard::query()->findOrFail($validated['dashboard_id']);

        if (isset($validated['connection_id'])) {
            $connection = Connection::query()->findOrFail($validated['connection_id']);
            abort_unless($connection->client_dashboard_id === (int) $validated['dashboard_id'], 404);
            abort_unless($connection->connector_type === $connectorType, 404);
        }

        $userId = $request->user()?->id;

        if ($userId === null) {
            abort(403);
        }

        try {
            $url = $oauth->authorizationUrl([
                'connector_type' => $validated['connector_type'],
                'dashboard_id' => (int) $validated['dashboard_id'],
                'connection_id' => isset($validated['connection_id']) ? (int) $validated['connection_id'] : null,
                'return_to' => $validated['return_to'],
                'user_id' => $userId,
            ]);
        } catch (RuntimeException $e) {
            return $this->redirectWithOAuthError($validated, $e->getMessage());
        }

        $request->session()->save();

        return redirect()->away($url);
    }

    public function callback(
        Request $request,
        GoogleOAuthService $oauth,
        GoogleOAuthPendingSession $pendingSession,
        SearchConsoleClient $searchConsole,
        GoogleAnalyticsAdminClient $googleAnalyticsAdmin,
        GoogleAdsApiClient $googleAds,
    ): RedirectResponse {
        if ($request->filled('error')) {
            return redirect()
                ->route('admin.dashboards.index')
                ->with('error', 'Google sign-in was cancelled or denied.');
        }

        $validated = $request->validate([
            'code' => ['required', 'string'],
            'state' => ['required', 'string'],
        ]);

        try {
            $context = $oauth->decodeState($validated['state']);
            $tokens = $oauth->exchangeCode($validated['code']);
        } catch (RuntimeException $e) {
            return redirect()
                ->route('admin.dashboards.index')
                ->with('error', $e->getMessage());
        }

        $connectorType = ConnectorType::from($context['connector_type']);
        $dashboard = ClientDashboard::query()->findOrFail($context['dashboard_id']);

        $refreshToken = $tokens['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            $existingToken = $pendingSession->refreshTokenFor($connectorType, $dashboard->id);

            if (is_string($existingToken) && $existingToken !== '') {
                $refreshToken = $existingToken;
            }
        }

        if (! is_string($refreshToken) || $refreshToken === '') {
            return $this->redirectWithOAuthError($context, 'Google did not return a refresh token. Revoke '.config('app.name', 'Atlas').' access in your Google account and connect again.');
        }

        try {
            $oauthOptions = match ($connectorType) {
                ConnectorType::SearchConsole => $searchConsole->listSites($refreshToken),
                ConnectorType::GoogleAnalytics => $googleAnalyticsAdmin->listProperties($refreshToken),
                ConnectorType::GoogleAds => $googleAds->listSelectableCustomers($refreshToken),
                default => [],
            };
        } catch (RuntimeException $e) {
            return $this->redirectWithOAuthError($context, $e->getMessage());
        }

        $userId = $request->user()?->id ?? $context['user_id'];

        if ($request->user() !== null && $request->user()->id !== $context['user_id']) {
            abort(403);
        }

        $googleEmail = null;
        $googleName = null;
        $accessToken = $tokens['access_token'] ?? null;

        if (is_string($accessToken) && $accessToken !== '') {
            try {
                $userInfo = app(GoogleTokenService::class)->fetchUserInfo($accessToken);
                $googleEmail = $userInfo['email'];
                $googleName = $userInfo['name'];
            } catch (RuntimeException) {
                // User info is optional; connection can still proceed.
            }
        }

        $pendingSession->store(
            connectorType: $connectorType,
            dashboardId: $dashboard->id,
            refreshToken: $refreshToken,
            oauthOptions: $oauthOptions,
            connectionId: isset($context['connection_id']) ? (int) $context['connection_id'] : null,
            userId: $userId,
            googleEmail: $googleEmail,
            googleName: $googleName,
        );

        $oauthProps = $pendingSession->flashProps(
            connectorType: $connectorType,
            oauthOptions: $oauthOptions,
            googleEmail: $googleEmail,
            googleName: $googleName,
        );

        $status = match ($connectorType) {
            ConnectorType::SearchConsole => $oauthOptions === []
                ? 'Google account connected, but no Search Console properties were returned. Confirm this account has property access in Google Search Console, then reconnect or pick a property if one appears below.'
                : 'Google account connected. Select a Search Console property and save the connection.',
            ConnectorType::GoogleAnalytics => $oauthOptions === []
                ? 'Google account connected, but no GA4 properties were returned. Confirm this account has Analytics access, then reconnect or pick a property if one appears below.'
                : 'Google account connected. Select a GA4 property and save the connection.',
            ConnectorType::GoogleAds => $oauthOptions === []
                ? 'Google account connected, but no Google Ads accounts were returned. Confirm this account has Ads access, then reconnect or pick an account if one appears below.'
                : 'Google account connected. Select a Google Ads account and save the connection.',
            default => 'Google account connected.',
        };

        $redirect = $this->redirectAfterOAuth($context)
            ->with(GoogleOAuthPendingSession::FLASH_KEY, $oauthProps)
            ->with('status', $status);

        if ($request->user() === null) {
            $request->session()->put('url.intended', $redirect->getTargetUrl());

            return redirect()
                ->route('login')
                ->with('status', 'Google account connected. Sign in to finish adding the connection.');
        }

        return $redirect;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function redirectAfterOAuth(array $context): RedirectResponse
    {
        $dashboardId = (int) $context['dashboard_id'];

        if (($context['return_to'] ?? '') === 'edit' && isset($context['connection_id'])) {
            return redirect()->route('admin.connections.edit', $context['connection_id']);
        }

        return redirect()->route('admin.dashboards.connections.create', $dashboardId);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function redirectWithOAuthError(array $context, string $message): RedirectResponse
    {
        if (isset($context['dashboard_id'], $context['return_to']) && $context['return_to'] === 'edit' && isset($context['connection_id'])) {
            return redirect()
                ->route('admin.connections.edit', $context['connection_id'])
                ->withErrors(['credentials' => $message]);
        }

        if (isset($context['dashboard_id'])) {
            return redirect()
                ->route('admin.dashboards.connections.create', $context['dashboard_id'])
                ->withErrors(['credentials' => $message]);
        }

        return redirect()
            ->route('admin.dashboards.index')
            ->with('error', $message);
    }
}
