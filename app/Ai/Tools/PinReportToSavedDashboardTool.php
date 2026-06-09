<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Models\AnalyticsReport;
use App\Models\SavedDashboard;
use App\Services\Client\SavedDashboardService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PinReportToSavedDashboardTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected SavedDashboardService $savedDashboards,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Pin a saved analytics report to a shared saved dashboard board.';
    }

    public function handle(Request $request): Stringable|string
    {
        $reportId = $request->integer('report_id') ?: $this->context->lastSavedReport?->id;

        if (! $reportId) {
            return $this->json([
                'success' => false,
                'error' => 'No report to pin. Save a report first.',
            ]);
        }

        $report = AnalyticsReport::query()
            ->active()
            ->where('client_dashboard_id', $this->context->dashboard->id)
            ->find($reportId);

        if (! $report) {
            return $this->json([
                'success' => false,
                'error' => 'Report not found on this dashboard.',
            ]);
        }

        $board = null;

        if ($request->filled('saved_dashboard_id')) {
            $board = SavedDashboard::query()
                ->where('client_dashboard_id', $this->context->dashboard->id)
                ->find($request->integer('saved_dashboard_id'));
        }

        if (! $board && $request->filled('title')) {
            $board = $this->savedDashboards->create($this->context->dashboard, $this->context->user, [
                'title' => $request->string('title')->toString(),
                'description' => $request->string('description')->toString() ?: null,
            ]);
        }

        if (! $board) {
            return $this->json([
                'success' => false,
                'error' => 'Provide saved_dashboard_id or title to create a new board.',
            ]);
        }

        $block = $this->savedDashboards->pinBlock($board, [
            'analytics_report_id' => $report->id,
            'title' => $request->string('block_title')->toString() ?: $report->prompt,
            'description' => $request->string('block_description')->toString() ?: null,
            'column_span' => $request->integer('column_span') ?: 1,
        ]);

        return $this->json([
            'success' => true,
            'saved_dashboard_id' => $board->id,
            'block_id' => $block->id,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'report_id' => $schema->integer(),
            'saved_dashboard_id' => $schema->integer(),
            'title' => $schema->string(),
            'description' => $schema->string(),
            'block_title' => $schema->string(),
            'block_description' => $schema->string(),
            'column_span' => $schema->integer(),
        ];
    }
}
