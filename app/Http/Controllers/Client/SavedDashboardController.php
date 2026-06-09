<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\RedirectsToDashboardTabs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\PinSavedDashboardBlockRequest;
use App\Http\Requests\Client\StoreSavedDashboardRequest;
use App\Http\Requests\Client\UpdateSavedDashboardBlockRequest;
use App\Http\Requests\Client\UpdateSavedDashboardRequest;
use App\Models\ClientDashboard;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Services\Client\SavedDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class SavedDashboardController extends Controller
{
    use RedirectsToDashboardTabs;

    public function index(Request $request, ClientDashboard $dashboard): RedirectResponse
    {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);

        return $this->redirectToSavedTab($dashboard, $request);
    }

    public function show(
        Request $request,
        ClientDashboard $dashboard,
        SavedDashboard $board,
    ): RedirectResponse {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);
        abort_unless($board->client_dashboard_id === $dashboard->id, 404);

        return $this->redirectToSavedTab($dashboard, $request, [
            'board' => $board->id,
        ]);
    }

    public function store(
        StoreSavedDashboardRequest $request,
        ClientDashboard $dashboard,
        SavedDashboardService $service,
    ): RedirectResponse {
        $board = $service->create($dashboard, $request->user(), $request->validated());

        return $this->redirectToSavedTab($dashboard, $request, [
            'board' => $board->id,
        ])->with('status', 'Saved dashboard created.');
    }

    public function update(
        UpdateSavedDashboardRequest $request,
        ClientDashboard $dashboard,
        SavedDashboard $board,
        SavedDashboardService $service,
    ): RedirectResponse {
        abort_unless($board->client_dashboard_id === $dashboard->id, 404);

        $service->update($board, $request->validated());

        return back()->with('status', 'Saved dashboard updated.');
    }

    public function destroy(
        Request $request,
        ClientDashboard $dashboard,
        SavedDashboard $board,
        SavedDashboardService $service,
    ): RedirectResponse {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);
        abort_unless($board->client_dashboard_id === $dashboard->id, 404);

        $service->delete($board);

        return $this->redirectToSavedTab($dashboard, $request)
            ->with('status', 'Saved dashboard deleted.');
    }

    public function pinBlock(
        PinSavedDashboardBlockRequest $request,
        ClientDashboard $dashboard,
        SavedDashboard $board,
        SavedDashboardService $service,
    ): RedirectResponse {
        abort_unless($board->client_dashboard_id === $dashboard->id, 404);

        $reportId = $request->integer('analytics_report_id');
        abort_unless(
            \App\Models\AnalyticsReport::query()
                ->active()
                ->where('client_dashboard_id', $dashboard->id)
                ->whereKey($reportId)
                ->exists(),
            404,
        );

        $service->pinBlock($board, $request->validated());

        return back()->with('status', 'Visual pinned.');
    }

    public function updateBlock(
        UpdateSavedDashboardBlockRequest $request,
        SavedDashboardBlock $block,
        SavedDashboardService $service,
    ): RedirectResponse {
        $service->updateBlock($block, $request->validated());

        return back()->with('status', 'Visual updated.');
    }

    public function destroyBlock(
        Request $request,
        SavedDashboardBlock $block,
        SavedDashboardService $service,
    ): RedirectResponse {
        abort_unless(
            $request->user()?->canAccessDashboard($block->savedDashboard->dashboard) ?? false,
            403,
        );

        $dashboard = $block->savedDashboard->dashboard;
        $service->unpinBlock($block);

        return back()->with('status', 'Visual removed.');
    }

}
