<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Agents\ConnectorBuilderAgentContext;
use App\Enums\ConnectorBlueprintStatus;
use App\Services\ConnectorBuilder\ConnectorBlueprintService;
use App\Support\ConnectorDashboardVisualization;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class SaveConnectorBlueprintTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected ConnectorBlueprintService $blueprints,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Create or update a read-only connector blueprint. Use oauth2_client_credentials for client ID/secret token exchange (Shopware-style), or bearer with token_request for custom token endpoints. Write endpoints are rejected.';
    }

    public function handle(Request $request): Stringable|string
    {
        try {
            $dashboardSpec = $request->array('dashboard_spec');
            $dashboardWarnings = $dashboardSpec !== []
                ? ConnectorDashboardVisualization::validateWidgetSql($dashboardSpec['widgets'] ?? [])
                : [];

            $blueprint = $this->blueprints->upsert(
                dashboard: $this->context->dashboard,
                session: $this->context->session,
                data: [
                    'slug' => $request->string('slug')->toString(),
                    'label' => $request->string('label')->toString(),
                    'status' => $request->string('status')->toString() ?: ConnectorBlueprintStatus::Draft->value,
                    'original_prompt' => $request->string('original_prompt')->toString() ?: $this->context->session->title,
                    'auth_config' => $request->array('auth_config'),
                    'credential_schema' => $request->array('credential_schema'),
                    'sync_config' => $request->array('sync_config'),
                    'transform_config' => $request->array('transform_config'),
                    'dashboard_spec' => $dashboardSpec,
                    'streams' => $request->array('streams'),
                ],
            );

            $this->context->blueprint = $blueprint;

            return $this->json([
                'success' => true,
                'blueprint_id' => $blueprint->id,
                'slug' => $blueprint->slug,
                'status' => $blueprint->status->value,
                'stream_count' => $blueprint->streams->count(),
                'dashboard_spec_warnings' => $dashboardWarnings,
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
            'slug' => $schema->string()->required(),
            'label' => $schema->string()->required(),
            'status' => $schema->string(),
            'original_prompt' => $schema->string(),
            'auth_config' => $schema->object([]),
            'credential_schema' => $schema->array()->items($schema->object([])),
            'sync_config' => $schema->object([]),
            'transform_config' => $schema->object([]),
            'dashboard_spec' => $schema->object([]),
            'streams' => $schema->array()->items($schema->object([])),
        ];
    }
}
