<?php

namespace App\Ai\Tools;

use App\Agents\ReportingAgentContext;
use App\Services\AI\DashboardAgentMemoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class ListDashboardMemoriesTool extends ReportingTool
{
    public function __construct(
        ReportingAgentContext $context,
        protected DashboardAgentMemoryService $memories,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'List saved dashboard memory entries before re-researching schema or rewriting SQL.';
    }

    public function handle(Request $request): Stringable|string
    {
        $flow = $request->string('agent_flow')->toString();

        return $this->json([
            'success' => true,
            'memories' => $this->memories->listForDashboard(
                $this->context->dashboard,
                $flow !== '' ? $flow : 'reporting',
            ),
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'agent_flow' => $schema->string(),
        ];
    }
}
