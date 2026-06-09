<?php

namespace App\Http\Controllers\Admin;

use App\Enums\ConnectorType;
use App\Http\Controllers\Controller;
use App\Ingestion\Connectors\SearchConsole\SearchConsoleClient;
use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Services\Google\GoogleOAuthPendingSession;
use App\Services\Google\GoogleOAuthService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class GoogleOAuthController extends Controller
{
    public function redirect(Request $request, GoogleOAuthService $oauth): RedirectResponse
    {
        $validated = $request->validate([
            'connector_type' => ['required', 'in:search_console'],
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

        try {
            $url = $oauth->authorizationUrl([
                'connector_type' => $validated['connector_type'],
                'dashboard_id' => (int) $validated['dashboard_id'],
                'connection_id' => isset($validated['connection_id']) ? (int) $validated['connection_id'] : null,
                'return_to' => $validated['return_to'],
            ]);
        } catch (RuntimeException $e) {
            return $this->redirectWithOAuthError($validated, $e->getMessage());
        }

        return redirect()->away($url);
    }

    public function callback(
        Request $request,
        GoogleOAuthService $oauth,
        GoogleOAuthPendingSession $pendingSession,
        SearchConsoleClient $searchConsole,
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

        $refreshToken = $tokens['refresh_token'] ?? null;

        if (! is_string($refreshToken) || $refreshToken === '') {
            return $this->redirectWithOAuthError($context, 'Google did not return a refresh token. Revoke '.config('app.name', 'Atlas').' access in your Google account and connect again.');
        }

        $connectorType = ConnectorType::from($context['connector_type']);
        $dashboard = ClientDashboard::query()->findOrFail($context['dashboard_id']);

        try {
            $sites = $searchConsole->listSites($refreshToken);
        } catch (RuntimeException $e) {
            return $this->redirectWithOAuthError($context, $e->getMessage());
        }

        if ($sites === []) {
            return $this->redirectWithOAuthError($context, 'No Search Console properties found for this Google account. Verify property access in Google Search Console first.');
        }

        $pendingSession->store(
            connectorType: $connectorType,
            dashboardId: $dashboard->id,
            refreshToken: $refreshToken,
            sites: $sites,
            connectionId: isset($context['connection_id']) ? (int) $context['connection_id'] : null,
        );

        return $this->redirectAfterOAuth($context)
            ->with('status', 'Google account connected. Select a Search Console property and save the connection.');
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
