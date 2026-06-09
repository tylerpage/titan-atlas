<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Enums\ReportVisualizationType;
use App\Services\Analytics\MetricRegistry;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateMetricDefinitionTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected MetricRegistry $registry,
        protected ReportQueryExecutor $executor,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Create a custom KPI definition with validated SQL template. Admin use only.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $sql = $request->string('sql_template')->toString();
            $vizType = ReportVisualizationType::from($request->string('visualization_type')->toString());

            $queryContext = new ReportQueryContext(
                dashboardId: $this->context->dashboard->id,
                startDate: $this->context->previewStartDate,
                endDate: $this->context->previewEndDate,
                compareStartDate: $this->context->previewCompareStartDate,
                compareEndDate: $this->context->previewCompareEndDate,
                connectionId: $request->integer('connection_id') ?: $this->context->connectionId,
            );

            $this->executor->execute($sql, $queryContext);

            $slug = $request->string('slug')->toString()
                ?: Str::slug($request->string('name')->toString());

            $metric = $this->registry->create($this->context->dashboard, $this->context->user, [
                'slug' => $slug,
                'name' => $request->string('name')->toString(),
                'description' => $request->string('description')->toString() ?: null,
                'formula_notes' => $request->string('formula_notes')->toString() ?: null,
                'sql_template' => $sql,
                'visualization_type' => $vizType,
                'visualization_config' => $request->array('visualization_config'),
                'connector_types' => $request->array('connector_types'),
            ]);

            $this->context->lastMetricDefinition = $metric;

            return $this->json([
                'success' => true,
                'metric' => $this->registry->explain($metric),
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
            'slug' => $schema->string(),
            'name' => $schema->string()->required(),
            'description' => $schema->string(),
            'formula_notes' => $schema->string(),
            'sql_template' => $schema->string()->required(),
            'visualization_type' => $schema->string()->required(),
            'visualization_config' => $schema->object()->required(),
            'connector_types' => $schema->array(),
            'connection_id' => $schema->integer(),
        ];
    }
}
