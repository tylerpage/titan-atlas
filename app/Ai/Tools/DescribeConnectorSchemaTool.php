<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Support\AnalyticsSchemaCatalog;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class DescribeConnectorSchemaTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected AnalyticsSchemaCatalog $catalog,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Returns connector entity mappings (orders, customers, products, sessions) for active dashboard connections, including Titan resource_type and payload fields.';
    }

    public function handle(Request $request): Stringable|string
    {
        $dashboard = $this->context->dashboard;
        $activeTypes = $dashboard->connections()
            ->where('is_active', true)
            ->pluck('connector_type')
            ->map(fn ($type) => $type->value)
            ->unique()
            ->values()
            ->all();

        $connectorType = $request->string('connector_type')->toString();

        $entities = $this->catalog->connectorEntitiesForTypes($activeTypes);

        if ($connectorType !== '') {
            $entities = collect($entities)
                ->where('connector', $connectorType)
                ->values()
                ->all();
        }

        return $this->json([
            'success' => true,
            'active_connectors' => $activeTypes,
            'connector_entities' => $entities,
            'data_modeling_notes' => [
                'recommended_star_schema' => [
                    'fact_orders' => 'raw_connector_payloads where resource_type = order',
                    'fact_sessions' => 'raw_connector_payloads where resource_type = session_attribution',
                    'dim_connection' => 'connections table',
                ],
                'note' => 'DDL is not executed. Use these as modeling recommendations.',
            ],
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'connector_type' => $schema->string(),
        ];
    }
}
