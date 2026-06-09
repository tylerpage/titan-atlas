<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Services\Analytics\MetricRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListMetricDefinitionsTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected MetricRegistry $registry,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Lists available KPI/metric definitions for this dashboard including slug, name, description, and visualization type.';
    }

    public function handle(Request $request): Stringable|string
    {
        $metrics = $this->registry->forDashboard($this->context->dashboard)
            ->map(fn ($metric) => $this->registry->explain($metric))
            ->values()
            ->all();

        return $this->json([
            'success' => true,
            'metrics' => $metrics,
            'count' => count($metrics),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
