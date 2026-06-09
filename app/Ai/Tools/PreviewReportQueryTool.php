<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class PreviewReportQueryTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected ReportQueryExecutor $executor,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Validate SQL before save_analytics_report. Uses :start_date, :end_date, :dashboard_id from the chat date picker. Returns sample rows and columns.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $context = new ReportQueryContext(
                dashboardId: $this->context->dashboard->id,
                startDate: $this->context->previewStartDate,
                endDate: $this->context->previewEndDate,
                compareStartDate: $this->context->previewCompareStartDate,
                compareEndDate: $this->context->previewCompareEndDate,
                connectionId: $request->integer('connection_id') ?: $this->context->connectionId,
            );

            $result = $this->executor->execute($request->string('sql')->toString(), $context);

            return $this->json([
                'success' => true,
                'columns' => $result['columns'],
                'rows' => array_slice($result['rows'], 0, 20),
                'row_count' => $result['row_count'],
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
            'sql' => $schema->string()->required(),
            'connection_id' => $schema->integer(),
        ];
    }
}
