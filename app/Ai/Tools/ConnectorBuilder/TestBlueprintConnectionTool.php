<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Support\DynamicConnectorBaseUrl;
use App\Enums\ConnectorBlueprintStatus;
use App\Ingestion\Connectors\DynamicConnector;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class TestBlueprintConnectionTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected DynamicConnector $connector,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Test blueprint credentials against the configured API probe or first stream.';
    }

    public function handle(Request $request): Stringable|string
    {
        $this->context->refreshBlueprint();

        if ($this->context->blueprint === null) {
            return $this->json([
                'success' => false,
                'error' => 'No blueprint exists for this session. Save a blueprint first.',
            ]);
        }

        $credentials = $this->resolveCredentials($request);

        if ($credentials === []) {
            return $this->json([
                'success' => false,
                'error' => 'No credentials available. Ask the user and store them with UpdateBlueprintCredentialsTool.',
            ]);
        }

        try {
            $response = $this->connector->probeConnection(
                $this->context->blueprint,
                $credentials,
                $this->context->session->session_config,
            );
            $this->context->blueprint->update(['status' => ConnectorBlueprintStatus::Ready]);
            $this->context->lastTestResult = ['success' => true];

            return $this->json([
                'success' => true,
                'message' => 'Connection test succeeded.',
                'response_keys' => array_keys($response),
            ]);
        } catch (\Throwable $e) {
            $this->context->lastTestResult = ['success' => false, 'error' => $e->getMessage()];

            return $this->json([
                'success' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveCredentials(Request $request): array
    {
        $provided = $request->array('credentials');

        if ($provided !== []) {
            $this->context->session->update([
                'pending_credentials' => array_merge($this->context->session->pending_credentials ?? [], $provided),
            ]);
        }

        return DynamicConnectorBaseUrl::mergeIntoCredentials(
            $this->context->session->fresh()->pending_credentials ?? [],
            $this->context->session->session_config,
            $this->context->blueprint,
        );
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'credentials' => $schema->object([]),
        ];
    }
}
