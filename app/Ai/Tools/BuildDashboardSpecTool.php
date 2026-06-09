<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ReportVisualizationType;
use App\Models\AnalyticsReport;
use App\Models\CoverPage;
use App\Models\SavedDashboard;
use App\Services\Admin\AnalyticsReportPlacementService;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use App\Services\Client\SavedDashboardService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class BuildDashboardSpecTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected ReportQueryExecutor $executor,
        protected AnalyticsReportPlacementService $placement,
        protected SavedDashboardService $savedDashboards,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Build a multi-widget dashboard from a spec. Validates SQL, saves reports, and optionally places on cover page or pins to saved dashboard.';
    }

    public function handle(Request $request): Stringable|string
    {
        $widgets = $request->array('widgets');
        $title = $request->string('title')->toString() ?: 'Dashboard';

        if ($widgets === []) {
            return $this->json([
                'success' => false,
                'error' => 'Provide at least one widget in the widgets array.',
            ]);
        }

        $createdReports = [];
        $errors = [];

        foreach ($widgets as $index => $widget) {
            try {
                $sql = $widget['sql'] ?? '';
                $vizType = ReportVisualizationType::from($widget['visualization_type'] ?? 'stat_card');

                $queryContext = new ReportQueryContext(
                    dashboardId: $this->context->dashboard->id,
                    startDate: $this->context->previewStartDate,
                    endDate: $this->context->previewEndDate,
                    compareStartDate: $this->context->previewCompareStartDate,
                    compareEndDate: $this->context->previewCompareEndDate,
                    connectionId: $widget['connection_id'] ?? $this->context->connectionId,
                );

                $this->executor->execute($sql, $queryContext);

                $report = AnalyticsReport::query()->create([
                    'client_dashboard_id' => $this->context->dashboard->id,
                    'analytics_report_session_id' => $this->context->session->id,
                    'created_by' => $this->context->user->id,
                    'prompt' => $widget['prompt'] ?? "Widget {$index}",
                    'sql' => $sql,
                    'visualization_type' => $vizType,
                    'visualization_config' => $widget['visualization_config'] ?? [],
                    'model' => config('titan.reporting.model'),
                ]);

                $createdReports[] = $report;
            } catch (\Throwable $e) {
                $errors[] = [
                    'widget_index' => $index,
                    'prompt' => $widget['prompt'] ?? null,
                    'error' => $e->getMessage(),
                ];
            }
        }

        if ($createdReports === []) {
            return $this->json([
                'success' => false,
                'error' => 'No widgets could be created.',
                'errors' => $errors,
            ]);
        }

        $placementResults = [];

        if ($request->filled('cover_page_id')) {
            $coverPage = CoverPage::query()
                ->where('client_dashboard_id', $this->context->dashboard->id)
                ->find($request->integer('cover_page_id'));

            if ($coverPage) {
                foreach ($createdReports as $report) {
                    $block = $this->placement->placeOnCoverPage(
                        $report,
                        $coverPage,
                        max(1, min(2, $request->integer('column_span') ?: 1)),
                    );
                    $placementResults[] = ['type' => 'cover_page', 'block_id' => $block->id, 'report_id' => $report->id];
                }
            }
        }

        $savedBoard = null;

        if ($request->filled('saved_dashboard_id')) {
            $savedBoard = SavedDashboard::query()
                ->where('client_dashboard_id', $this->context->dashboard->id)
                ->find($request->integer('saved_dashboard_id'));
        } elseif ($request->filled('saved_dashboard_title')) {
            $savedBoard = $this->savedDashboards->create($this->context->dashboard, $this->context->user, [
                'title' => $request->string('saved_dashboard_title')->toString(),
                'description' => $request->string('saved_dashboard_description')->toString() ?: null,
            ]);
        }

        if ($savedBoard) {
            foreach ($createdReports as $report) {
                $block = $this->savedDashboards->pinBlock($savedBoard, [
                    'analytics_report_id' => $report->id,
                    'title' => $report->prompt,
                    'column_span' => max(1, min(2, $request->integer('column_span') ?: 1)),
                ]);
                $placementResults[] = ['type' => 'saved_dashboard', 'block_id' => $block->id, 'report_id' => $report->id];
            }
        }

        $this->context->lastSavedReport = $createdReports[0];
        $this->context->lastDashboardSpec = [
            'title' => $title,
            'report_ids' => collect($createdReports)->pluck('id')->all(),
            'placement' => $placementResults,
        ];

        $this->context->session->update(['status' => AnalyticsReportSessionStatus::Completed]);

        return $this->json([
            'success' => true,
            'title' => $title,
            'reports' => collect($createdReports)->map(fn ($r) => [
                'id' => $r->id,
                'prompt' => $r->prompt,
                'visualization_type' => $r->visualization_type->value,
            ])->all(),
            'placement' => $placementResults,
            'errors' => $errors,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string(),
            'widgets' => $schema->array()->required(),
            'cover_page_id' => $schema->integer(),
            'saved_dashboard_id' => $schema->integer(),
            'saved_dashboard_title' => $schema->string(),
            'saved_dashboard_description' => $schema->string(),
            'column_span' => $schema->integer(),
        ];
    }
}
