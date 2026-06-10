<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ReportVisualizationType;
use App\Models\AnalyticsReport;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateAnalyticsReportTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected ReportQueryExecutor $executor,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'REQUIRED for any data answer. Validates SQL, saves a reusable dashboard widget (table, line_chart, or stat_card), and returns a preview in one step. SQL must use :start_date and :end_date placeholders.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $sql = $request->string('sql')->toString();
            $vizType = ReportVisualizationType::from($request->string('visualization_type')->toString());

            $queryContext = new ReportQueryContext(
                dashboardId: $this->context->dashboard->id,
                startDate: $this->context->previewStartDate,
                endDate: $this->context->previewEndDate,
                compareStartDate: $this->context->previewCompareStartDate,
                compareEndDate: $this->context->previewCompareEndDate,
                connectionId: $request->integer('connection_id') ?: $this->context->connectionId,
            );

            $preview = $this->executor->execute($sql, $queryContext);
            $config = $request->array('visualization_config');

            $report = AnalyticsReport::query()->create([
                'client_dashboard_id' => $this->context->dashboard->id,
                'analytics_report_session_id' => $this->context->session->id,
                'created_by' => $this->context->user->id,
                'prompt' => $request->string('prompt')->toString(),
                'sql' => $sql,
                'visualization_type' => $vizType,
                'visualization_config' => $config,
                'model' => config('titan.reporting.model'),
            ]);

            $this->context->session->update(['status' => AnalyticsReportSessionStatus::Completed]);
            $this->context->lastSavedReport = $report;
            $this->context->lastPreviewSql = $this->normalizeSql($sql);
            $this->context->lastPreviewResult = $preview;

            return $this->json([
                'success' => true,
                'report_id' => $report->id,
                'visualization_type' => $vizType->value,
                'preview' => [
                    'columns' => $preview['columns'],
                    'rows' => array_slice($preview['rows'], 0, 10),
                ],
            ]);
        } catch (\Throwable $e) {
            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'prompt' => $schema->string()->required(),
            'sql' => $schema->string()->required(),
            'visualization_type' => $schema->string()->required(),
            'visualization_config' => $schema->object()->required(),
            'connection_id' => $schema->integer(),
        ];
    }

    protected function normalizeSql(string $sql): string
    {
        return preg_replace('/\s+/', ' ', trim($sql)) ?? trim($sql);
    }
}
