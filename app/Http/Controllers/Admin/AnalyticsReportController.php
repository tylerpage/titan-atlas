<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PlaceAnalyticsReportRequest;
use App\Http\Requests\Admin\SendReportMessageRequest;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\CoverPage;
use App\Services\Admin\AnalyticsReportPlacementService;
use App\Services\AI\ChatMessageSerializer;
use App\Services\AI\ReportingAgentService;
use App\Services\Analytics\ReportDataMapper;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AnalyticsReportController extends Controller
{
    public function index(ClientDashboard $dashboard): Response
    {
        $dashboard->load('company');

        return Inertia::render('Admin/Dashboards/Reports/Index', [
            'dashboard' => $this->serializeDashboard($dashboard),
            'reports' => $this->serializeReports(
                $dashboard->analyticsReports()->active()->with('creator:id,name')->get(),
            ),
            'archivedReports' => $this->serializeReports(
                $dashboard->analyticsReports()->archived()->with('creator:id,name')->get(),
                includeArchivedAt: true,
            ),
        ]);
    }

    public function ask(
        Request $request,
        ClientDashboard $dashboard,
        ReportQueryExecutor $executor,
        ReportDataMapper $mapper,
        ChatMessageSerializer $messageSerializer,
        ?AnalyticsReportSession $session = null,
    ): Response {
        $dashboard->load(['company', 'coverPages', 'connections']);

        $session?->load(['messages', 'report']);

        $previewStart = $request->input('preview_start', now()->subDays(29)->toDateString());
        $previewEnd = $request->input('preview_end', now()->toDateString());
        $start = Carbon::parse($previewStart)->startOfDay();
        $end = Carbon::parse($previewEnd)->endOfDay();

        $coverPages = $dashboard->coverPages->map(fn (CoverPage $page) => [
            'id' => $page->id,
            'title' => $page->title,
            'is_active' => $page->is_active,
        ]);

        $savedReport = null;
        $reportPreview = null;

        if ($session?->report) {
            $report = $session->report;
            $savedReport = [
                'id' => $report->id,
                'prompt' => $report->prompt,
                'visualization_type' => $report->visualization_type->value,
                'sql' => $report->sql,
            ];

            try {
                $context = new ReportQueryContext(
                    dashboardId: $dashboard->id,
                    startDate: $start,
                    endDate: $end,
                );
                $queryResult = $executor->execute($report->sql, $context);
                $reportPreview = $mapper->toBlockPayload(
                    $report->visualization_type,
                    $queryResult,
                    $report->visualization_config ?? [],
                );
            } catch (\Throwable) {
                $reportPreview = null;
            }
        }

        return Inertia::render('Admin/Dashboards/Reports/Ask', [
            'dashboard' => $this->serializeDashboard($dashboard),
            'session' => $session ? [
                'id' => $session->id,
                'status' => $session->status->value,
                'messages' => $messageSerializer->serialize($session->messages, $dashboard, $start, $end),
            ] : null,
            'savedReport' => $savedReport,
            'reportPreview' => $reportPreview,
            'coverPages' => $coverPages,
            'defaultPreviewStart' => $previewStart,
            'defaultPreviewEnd' => $previewEnd,
        ]);
    }

    public function sendMessage(
        SendReportMessageRequest $request,
        ClientDashboard $dashboard,
        ReportingAgentService $agent,
    ): RedirectResponse {
        $session = $request->filled('session_id')
            ? AnalyticsReportSession::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->findOrFail($request->integer('session_id'))
            : null;

        $result = $agent->queueMessage(
            dashboard: $dashboard,
            user: $request->user(),
            message: $request->string('message')->toString(),
            session: $session,
            previewStart: $request->input('preview_start'),
            previewEnd: $request->input('preview_end'),
        );

        return redirect()
            ->route('admin.dashboards.reports.ask', [
                'dashboard' => $dashboard->id,
                'session' => $result['session']->id,
            ])
            ->with('status', 'The reporting assistant is working on your question…');
    }

    public function place(
        PlaceAnalyticsReportRequest $request,
        ClientDashboard $dashboard,
        AnalyticsReport $report,
        AnalyticsReportPlacementService $placement,
    ): RedirectResponse {
        abort_unless($report->client_dashboard_id === $dashboard->id, 404);
        abort_if($report->isArchived(), 422, 'Archived reports cannot be placed on cover pages.');

        $coverPage = CoverPage::query()
            ->where('client_dashboard_id', $dashboard->id)
            ->findOrFail($request->integer('cover_page_id'));

        $block = $placement->placeOnCoverPage(
            $report,
            $coverPage,
            $request->integer('column_span') ?: 1,
        );

        return redirect()
            ->route('admin.cover-pages.edit', $coverPage)
            ->with([
                'status' => 'Report added as block #'.$block->id.'.',
                'focused_block_id' => $block->id,
            ]);
    }

    public function archive(AnalyticsReport $report): RedirectResponse
    {
        $dashboard = $report->dashboard;
        $report->archive();

        return redirect()
            ->route('admin.dashboards.reports.index', $dashboard)
            ->with('status', 'Report archived.');
    }

    public function restore(AnalyticsReport $report): RedirectResponse
    {
        $dashboard = $report->dashboard;
        $report->restore();

        return redirect()
            ->route('admin.dashboards.reports.index', $dashboard)
            ->with('status', 'Report restored.');
    }

    /**
     * @param  \Illuminate\Support\Collection<int, AnalyticsReport>  $reports
     * @return list<array<string, mixed>>
     */
    protected function serializeReports($reports, bool $includeArchivedAt = false): array
    {
        return $reports->map(function (AnalyticsReport $report) use ($includeArchivedAt) {
            $payload = [
                'id' => $report->id,
                'prompt' => $report->prompt,
                'visualization_type' => $report->visualization_type->value,
                'sql' => $report->sql,
                'model' => $report->model,
                'created_at' => $report->created_at?->toIso8601String(),
                'creator_name' => $report->creator?->name,
            ];

            if ($includeArchivedAt) {
                $payload['archived_at'] = $report->archived_at?->toIso8601String();
            }

            return $payload;
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeDashboard(ClientDashboard $dashboard): array
    {
        return [
            'id' => $dashboard->id,
            'name' => $dashboard->name,
            'company_name' => $dashboard->company->name,
        ];
    }
}
