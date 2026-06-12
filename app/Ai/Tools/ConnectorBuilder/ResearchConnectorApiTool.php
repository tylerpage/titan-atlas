<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Agents\ConnectorBuilderAgentContext;
use App\Services\AI\DashboardAgentMemoryService;
use App\Support\DynamicConnectorAuth;
use App\Support\DynamicConnectorReadOnlyGuard;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Http;
use Laravel\Ai\Tools\Request;
use Stringable;

class ResearchConnectorApiTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected DashboardAgentMemoryService $memories,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Capture structured API research notes for read-only connectors: auth method (including oauth2_client_credentials), base URL, token endpoint, list/fetch endpoints, pagination, and recommended streams.';
    }

    public function handle(Request $request): Stringable|string
    {
        $docsUrl = $request->string('docs_url')->toString();
        $docsHint = null;

        if ($docsUrl !== '') {
            try {
                $response = Http::timeout(10)->get($docsUrl);
                if ($response->successful()) {
                    $docsHint = 'Documentation URL responded successfully. Use your knowledge of this API combined with the user request.';
                }
            } catch (\Throwable $e) {
                $docsHint = 'Could not fetch docs URL: '.$e->getMessage();
            }
        }

        $integrationName = $request->string('integration_name')->toString();
        $slug = str($integrationName)->slug()->toString() ?: 'integration';

        $this->memories->remember($this->context->dashboard, [
            'memory_key' => $slug.':api_research',
            'category' => 'api_research',
            'agent_flow' => 'connector_builder',
            'title' => $integrationName.' API research',
            'content' => json_encode([
                'auth_type' => $request->string('auth_type')->toString(),
                'base_url' => $request->string('base_url')->toString(),
                'test_endpoint' => $request->string('test_endpoint')->toString(),
                'rate_limits' => $request->string('rate_limits')->toString(),
                'notes' => $request->string('notes')->toString(),
                'recommended_streams' => $request->array('recommended_streams'),
                'docs_hint' => $docsHint,
            ], JSON_UNESCAPED_SLASHES),
            'source_tool' => self::class,
            'metadata' => ['blueprint_slug' => $slug],
        ], $this->context->user);

        return $this->json([
            'success' => true,
            'integration_name' => $integrationName,
            'auth_type' => $request->string('auth_type')->toString(),
            'base_url' => $request->string('base_url')->toString(),
            'test_endpoint' => $request->string('test_endpoint')->toString(),
            'rate_limits' => $request->string('rate_limits')->toString(),
            'recommended_streams' => $request->array('recommended_streams'),
            'notes' => $request->string('notes')->toString(),
            'docs_hint' => $docsHint,
            'allowed_auth_types' => DynamicConnectorAuth::allowedTypes(),
            'oauth2_client_credentials_guidance' => DynamicConnectorAuth::agentOAuthGuidance(),
            'read_only_policy' => app(DynamicConnectorReadOnlyGuard::class)->policyNotice(),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'integration_name' => $schema->string()->required(),
            'auth_type' => $schema->string()->required(),
            'base_url' => $schema->string()->required(),
            'test_endpoint' => $schema->string(),
            'rate_limits' => $schema->string(),
            'notes' => $schema->string(),
            'docs_url' => $schema->string(),
            'recommended_streams' => $schema->array()->items($schema->object([
                'stream_key' => $schema->string(),
                'resource_type' => $schema->string(),
                'path_template' => $schema->string(),
                'description' => $schema->string(),
            ])),
        ];
    }
}
