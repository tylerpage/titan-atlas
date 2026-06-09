<?php

namespace App\Http\Controllers;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        /** @var ClientDashboard|null $resolvedDashboard */
        $resolvedDashboard = $request->attributes->get('resolved_dashboard');

        if ($resolvedDashboard instanceof ClientDashboard) {
            return redirect()->route('client.dashboard.show', $resolvedDashboard);
        }

        $user = Auth::user();

        if ($user?->role === UserRole::Admin) {
            return redirect()->route('admin.dashboards.index');
        }

        $dashboard = $user?->clientDashboards()->first();

        if ($dashboard) {
            return redirect()->route('client.dashboard.show', $dashboard);
        }

        return redirect()->route('login');
    }
}
