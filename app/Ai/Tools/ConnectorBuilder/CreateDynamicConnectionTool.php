<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Agents\ConnectorBuilderAgentContext;
use App\Enums\ConnectorBlueprintStatus;
use App\Services\ConnectorBuilder\CreateDynamicConnectionService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class CreateDynamicConnectionTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected CreateDynamicConnectionService $connections,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Create a dynamic connection from the blueprint, link it, and queue a backfill sync job.';
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

        $credentials = $this->context->session->pending_credentials ?? [];

        if ($credentials === []) {
            return $this->json([
                'success' => false,
                'error' => 'No credentials stored. Collect credentials first.',
            ]);
        }

        try {
            $connection = $this->connections->create(
                dashboard: $this->context->dashboard,
                blueprint: $this->context->blueprint,
                name: $request->string('name')->toString() ?: $this->context->blueprint->label,
                credentials: $credentials,
            );

            $this->context->connection = $connection;
            $this->context->blueprint->update(['status' => ConnectorBlueprintStatus::Active]);
            $this->context->blueprint = $this->context->blueprint->fresh(['streams', 'connection']);

            return $this->json([
                'success' => true,
                'connection_id' => $connection->id,
                'connection_name' => $connection->name,
                'message' => 'Connection created and backfill queued.',
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
            'name' => $schema->string(),
        ];
    }
}
