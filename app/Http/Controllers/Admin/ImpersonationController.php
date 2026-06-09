<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\ImpersonationService;
use Illuminate\Http\RedirectResponse;

class ImpersonationController extends Controller
{
    public function store(User $user, ImpersonationService $impersonation): RedirectResponse
    {
        $impersonation->start(request()->user(), $user);

        $dashboard = $user->clientDashboards()->first();

        if ($dashboard) {
            return redirect()->route('client.dashboard.show', $dashboard);
        }

        return redirect()->route('home');
    }

    public function destroy(ImpersonationService $impersonation): RedirectResponse
    {
        abort_unless($impersonation->isImpersonating(), 403);

        $impersonation->stop();

        return redirect()->route('admin.dashboards.index');
    }
}
