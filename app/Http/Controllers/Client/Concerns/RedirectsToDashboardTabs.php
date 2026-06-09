<?php

namespace App\Http\Controllers\Client\Concerns;

use App\Models\ClientDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

trait RedirectsToDashboardTabs
{
    protected function redirectToDashboardTab(
        ClientDashboard $dashboard,
        string $tab,
        array $query = [],
    ): RedirectResponse {
        return redirect()->route('client.dashboard.show', [
            'dashboard' => $dashboard,
            'tab' => $tab,
            ...$query,
        ]);
    }

    protected function redirectToAiTab(
        ClientDashboard $dashboard,
        Request $request,
        array $query = [],
    ): RedirectResponse {
        $preview = [];

        if ($request->filled('preview_start')) {
            $preview['preview_start'] = $request->input('preview_start');
        }

        if ($request->filled('preview_end')) {
            $preview['preview_end'] = $request->input('preview_end');
        }

        return $this->redirectToDashboardTab($dashboard, 'ai', [...$preview, ...$query]);
    }

    protected function redirectToSavedTab(
        ClientDashboard $dashboard,
        Request $request,
        array $query = [],
    ): RedirectResponse {
        $preview = [];

        if ($request->filled('preview_start')) {
            $preview['preview_start'] = $request->input('preview_start');
        }

        if ($request->filled('preview_end')) {
            $preview['preview_end'] = $request->input('preview_end');
        }

        return $this->redirectToDashboardTab($dashboard, 'saved', [...$preview, ...$query]);
    }
}
