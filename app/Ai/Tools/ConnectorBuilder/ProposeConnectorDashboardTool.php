<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Enums\ReportVisualizationType;
use App\Models\AnalyticsReport;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use App\Services\ConnectorBuilder\ConnectorBlueprintService;
use Carbon\Carbon;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ProposeConnectorDashboardTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected ReportQueryExecutor $executor,
        protected ConnectorBlueprintService $blueprints,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Validate dashboard widget SQL, save analytics reports, and store dashboard_spec on the blueprint.';
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
            ?? $this->context->blueprint->connection_id;

        $startDate = Carbon::now()->subDays(29)->startOfDay();
        $endDate = Carbon::now()->endOfDay();
        $created = [];
        $errors = [];

        foreach ($widgets as $index => $widget) {
            try {
                $sql = (string) ($widget['sql'] ?? '');
                $vizType = ReportVisualizationType::from((string) ($widget['visualization_type'] ?? 'table'));

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

                $created[] = [
                    'report_id' => $report->id,
                    'prompt' => $report->prompt,
                    'row_count' => $preview['row_count'] ?? count($preview['rows'] ?? []),
                ];
            } catch (\Throwable $e) {
                $errors[] = [
                    'widget_index' => $index,
                    'error' => $e->getMessage(),
                ];
            }
        }

        $dashboardSpec = [
            'title' => $request->string('title')->toString() ?: $this->context->blueprint->label.' Dashboard',
            'widgets' => $widgets,
            'created_report_ids' => array_column($created, 'report_id'),
        ];

        $this->context->blueprint->update(['dashboard_spec' => $dashboardSpec]);
        $this->context->lastDashboardSpec = $dashboardSpec;

        return $this->json([
            'success' => $errors === [],
            'created_reports' => $created,
            'errors' => $errors,
            'dashboard_spec' => $dashboardSpec,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string(),
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
