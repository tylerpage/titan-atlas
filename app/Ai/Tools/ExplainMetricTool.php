<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Services\Analytics\MetricRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ExplainMetricTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected MetricRegistry $registry,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Returns full documentation for a metric: definition, formula notes, SQL template, visualization config, and applicable connectors.';
    }

    public function handle(Request $request): Stringable|string
    {
        $slug = $request->string('slug')->toString();

        if ($slug === '') {
            return $this->json([
                'success' => false,
                'error' => 'Provide a metric slug (e.g. revenue, orders, avg_order_value).',
            ]);
        }

        $metric = $this->registry->findForDashboard($this->context->dashboard, $slug);

        if (! $metric) {
            return $this->json([
                'success' => false,
                'error' => "Metric '{$slug}' not found.",
            ]);
        }

        return $this->json([
            'success' => true,
            'metric' => $this->registry->explain($metric),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'slug' => $schema->string()->required(),
        ];
    }
}
