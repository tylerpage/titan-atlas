<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Services\AI\DashboardAgentMemoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListDashboardMemoriesTool extends ConnectorBuilderTool
{
    public function __construct(
        \App\Agents\ConnectorBuilderAgentContext $context,
        protected DashboardAgentMemoryService $memories,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'List saved dashboard memory entries for this connector builder session.';
    }

    public function handle(Request $request): Stringable|string
    {
        return $this->json([
            'success' => true,
            'memories' => $this->memories->listForDashboard(
                $this->context->dashboard,
                'connector_builder',
            ),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [];
    }
}
