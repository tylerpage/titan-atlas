<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Agents\ConnectorBuilderAgentContext;
use App\Models\AnalyticsReport;
use App\Models\SavedDashboard;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use App\Services\Client\SavedDashboardService;
use App\Support\ConnectorDashboardVisualization;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeConnectorDashboardTool extends ConnectorBuilderTool
{
    protected const SQL_HINT = 'Query raw_connector_payloads r JOIN connections c ON c.id = r.connection_id, filter c.client_dashboard_id = :dashboard_id and r.resource_type, and read JSON via json_extract(r.payload, \'$.field\').';

    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected ReportQueryExecutor $executor,
        protected SavedDashboardService $savedDashboards,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Validate dashboard widget SQL, save analytics reports, create a saved dashboard board, and store dashboard_spec on the blueprint.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->context->refreshBlueprint();

        if ($this->context->blueprint === null) {
            return $this->json([
                'success' => false,
                'error' => 'No blueprint exists for this session.',
            ]);
        }

        $widgets = $request->array('widgets');

        if ($widgets === []) {
            $widgets = $this->context->blueprint->dashboard_spec['widgets'] ?? [];
        }

        if ($widgets === []) {
            return $this->json([
                'success' => false,
                'error' => 'Provide at least one widget.',
            ]);
        }

        $connectionId = $this->context->connection?->id
            ?? $this->context->blueprint->connections()->latest()->value('id');

        if ($connectionId === null) {
            return $this->json([
                'success' => false,
                'error' => 'Create a connection first with CreateDynamicConnectionTool before proposing dashboard widgets.',
            ]);
        }

        $title = $request->string('title')->toString() ?: $this->context->blueprint->label.' Dashboard';
        $startDate = Carbon::now()->subDays(29)->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        $createdReports = [];
        $errors = [];

        foreach ($widgets as $index => $widget) {
            try {
                $sql = (string) ($widget['sql'] ?? '');
                $vizType = ConnectorDashboardVisualization::normalize(
                    (string) ($widget['visualization_type'] ?? 'table'),
                );

                $queryContext = new ReportQueryContext(
                    dashboardId: $this->context->dashboard->id,
                    startDate: $startDate,
                    endDate: $endDate,
                    connectionId: $widget['connection_id'] ?? $connectionId,
                );

                $preview = $this->executor->execute($sql, $queryContext);

                $report = AnalyticsReport::query()->create([
                    'client_dashboard_id' => $this->context->dashboard->id,
                    'created_by' => $this->context->user->id,
                    'prompt' => (string) ($widget['prompt'] ?? "Widget {$index}"),
                    'sql' => $sql,
                    'visualization_type' => $vizType,
                    'visualization_config' => $widget['visualization_config'] ?? [],
                    'model' => config('titan.connector_builder.model'),
                ]);

                $createdReports[] = [
                    'report' => $report,
                    'row_count' => $preview['row_count'] ?? count($preview['rows'] ?? []),
                    'visualization_type' => $vizType->value,
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'widget_index' => $index,
                    'prompt' => $widget['prompt'] ?? null,
                    'error' => $e->getMessage(),
                    'hint' => self::SQL_HINT,
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

        $savedBoard = $this->resolveSavedDashboard($request, $title);
        $pinnedBlocks = [];

        foreach ($createdReports as $item) {
            $report = $item['report'];
            $block = $this->savedDashboards->pinBlock($savedBoard, [
                'analytics_report_id' => $report->id,
                'title' => $report->prompt,
                'column_span' => max(1, min(2, $request->integer('column_span') ?: 1)),
            ]);
            $pinnedBlocks[] = [
                'block_id' => $block->id,
                'report_id' => $report->id,
            ];
        }

        $normalizedWidgets = array_map(function (array $widget) {
            if (! isset($widget['visualization_type'])) {
                return $widget;
            }

            $widget['visualization_type'] = ConnectorDashboardVisualization::normalize(
                (string) $widget['visualization_type'],
            )->value;

            return $widget;
        }, $widgets);

        $dashboardSpec = [
            'title' => $title,
            'widgets' => $normalizedWidgets,
            'created_report_ids' => collect($createdReports)->pluck('report.id')->all(),
            'saved_dashboard_id' => $savedBoard->id,
            'saved_dashboard_title' => $savedBoard->title,
            'pinned_blocks' => $pinnedBlocks,
        ];

        $this->context->blueprint->update(['dashboard_spec' => $dashboardSpec]);
        $this->context->lastDashboardSpec = $dashboardSpec;

        return $this->json([
            'success' => $errors === [],
            'saved_dashboard_id' => $savedBoard->id,
            'saved_dashboard_title' => $savedBoard->title,
            'created_reports' => collect($createdReports)->map(fn (array $item) => [
                'report_id' => $item['report']->id,
                'prompt' => $item['report']->prompt,
                'visualization_type' => $item['visualization_type'],
                'row_count' => $item['row_count'],
            ])->all(),
            'pinned_blocks' => $pinnedBlocks,
            'errors' => $errors,
            'dashboard_spec' => $dashboardSpec,
        ]);
    }

    protected function resolveSavedDashboard(Request $request, string $title): SavedDashboard
    {
        if ($request->filled('saved_dashboard_id')) {
            $board = SavedDashboard::query()
                ->where('client_dashboard_id', $this->context->dashboard->id)
                ->find($request->integer('saved_dashboard_id'));

            if ($board !== null) {
                return $board;
            }
        }

        $boardTitle = $request->string('saved_dashboard_title')->toString() ?: $title;

        return $this->savedDashboards->create($this->context->dashboard, $this->context->user, [
            'title' => $boardTitle,
            'description' => $request->string('saved_dashboard_description')->toString() ?: null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string(),
            'saved_dashboard_id' => $schema->integer(),
            'saved_dashboard_title' => $schema->string(),
            'saved_dashboard_description' => $schema->string(),
            'column_span' => $schema->integer(),
            'widgets' => $schema->array()->items($schema->object([
                'prompt' => $schema->string(),
                'sql' => $schema->string(),
                'visualization_type' => $schema->string(),
                'visualization_config' => $schema->object([]),
                'connection_id' => $schema->integer(),
            ])),
        ];
    }
}
