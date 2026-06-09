<?php

namespace App\Http\Controllers\Client;

use App\Http\Controllers\Client\Concerns\RedirectsToDashboardTabs;
use App\Http\Controllers\Controller;
use App\Http\Requests\Client\PinReportFromChatRequest;
use App\Http\Requests\Client\SendReportMessageRequest;
use App\Http\Requests\Client\StoreSavedDashboardRequest;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\SavedDashboard;
use App\Services\AI\ReportingAgentService;
use App\Services\Client\SavedDashboardService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AnalyticsReportController extends Controller
{
    use RedirectsToDashboardTabs;

    public function chat(
        Request $request,
        ClientDashboard $dashboard,
        ?AnalyticsReportSession $session = null,
    ): RedirectResponse {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);

        if ($session) {
            abort_unless(
                $session->client_dashboard_id === $dashboard->id
                    && $session->user_id === $request->user()->id,
                404,
            );
        }

        $query = ['ai_view' => 'chat'];

        if ($session) {
            $query['session'] = $session->id;
        }

        return $this->redirectToAiTab($dashboard, $request, $query);
    }

    public function sessions(Request $request, ClientDashboard $dashboard): RedirectResponse
    {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);

        return $this->redirectToAiTab($dashboard, $request, ['ai_view' => 'history']);
    }

    public function sendMessage(
        SendReportMessageRequest $request,
        ClientDashboard $dashboard,
        ReportingAgentService $agent,
    ): RedirectResponse {
        $session = $request->filled('session_id')
            ? AnalyticsReportSession::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->where('user_id', $request->user()->id)
                ->findOrFail($request->integer('session_id'))
            : null;

        $result = $agent->queueMessage(
            dashboard: $dashboard,
            user: $request->user(),
            message: $request->string('message')->toString(),
            session: $session,
            previewStart: $request->input('preview_start'),
            previewEnd: $request->input('preview_end'),
            clientMode: true,
        );

        return $this->redirectToAiTab($dashboard, $request, [
            'session' => $result['session']->id,
            'ai_view' => 'chat',
        ])->with('status', 'Thinking about your question…');
    }

    public function pinReport(
        PinReportFromChatRequest $request,
        ClientDashboard $dashboard,
        AnalyticsReport $report,
        SavedDashboardService $service,
    ): RedirectResponse {
        abort_unless($request->user()?->canAccessDashboard($dashboard), 403);
        abort_unless($report->client_dashboard_id === $dashboard->id, 404);
        abort_if($report->isArchived(), 404);

        $board = null;

        if ($request->filled('saved_dashboard_id')) {
            $board = SavedDashboard::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->findOrFail($request->integer('saved_dashboard_id'));
        } else {
            $board = $service->create($dashboard, $request->user(), [
                'title' => $request->string('board_title')->toString(),
                'description' => $request->input('board_description'),
            ]);
        }

        $service->pinBlock($board, [
            'analytics_report_id' => $report->id,
            'title' => $request->input('title') ?: $report->prompt,
            'description' => $request->input('description'),
            'column_span' => $request->integer('column_span') ?: 1,
        ]);

        return $this->redirectToSavedTab($dashboard, $request, [
            'board' => $board->id,
        ])->with('status', 'Pinned to '.$board->title);
    }

    public function quickCreateBoardAndPin(
        StoreSavedDashboardRequest $request,
        ClientDashboard $dashboard,
        SavedDashboardService $service,
    ): RedirectResponse {
        $board = $service->create($dashboard, $request->user(), $request->validated());

        if ($request->filled('analytics_report_id')) {
            abort_unless(
                AnalyticsReport::query()
                    ->active()
                    ->where('client_dashboard_id', $dashboard->id)
                    ->whereKey($request->integer('analytics_report_id'))
                    ->exists(),
                404,
            );

            $service->pinBlock($board, [
                'analytics_report_id' => $request->integer('analytics_report_id'),
                'title' => $request->input('block_title'),
                'description' => $request->input('block_description'),
                'column_span' => $request->integer('column_span') ?: 1,
            ]);
        }

        return $this->redirectToSavedTab($dashboard, $request, [
            'board' => $board->id,
        ])->with('status', 'Saved dashboard created.');
    }
}
