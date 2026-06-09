<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Controller;
use App\Models\ClientDashboard;
use App\Models\DashboardShareLink;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class DashboardShareController extends Controller
{
    public function store(Request $request, ClientDashboard $dashboard): JsonResponse
    {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);

        $query = array_filter([
            'tab' => $request->input('tab'),
            'cover_page' => $request->input('cover_page'),
            'range' => $request->input('range'),
            'compare' => $request->input('compare'),
            'connection' => $request->input('connection'),
            'start' => $request->input('start'),
            'end' => $request->input('end'),
        ], fn ($value) => $value !== null && $value !== '');

        $link = DashboardShareLink::query()->create([
            'code' => DashboardShareLink::generateCode(),
            'client_dashboard_id' => $dashboard->id,
            'created_by_user_id' => $request->user()->id,
            'query' => $query,
        ]);

        return response()->json([
            'url' => $link->shortUrl(),
            'code' => $link->code,
        ]);
    }

    public function show(string $code): RedirectResponse
    {
        $link = DashboardShareLink::query()
            ->with('clientDashboard')
            ->where('code', $code)
            ->firstOrFail();

        return redirect()->route('client.dashboard.show', [
            'dashboard' => $link->clientDashboard,
            ...$link->query,
        ]);
    }
}
