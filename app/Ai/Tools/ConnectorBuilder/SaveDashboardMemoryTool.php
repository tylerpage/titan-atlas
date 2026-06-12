<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Services\AI\DashboardAgentMemoryService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Stringable;

class SaveDashboardMemoryTool extends ConnectorBuilderTool
{
    public function __construct(
        \App\Agents\ConnectorBuilderAgentContext $context,
        protected DashboardAgentMemoryService $memories,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Save a reusable dashboard-scoped connector finding for future builder chats.';
    }

    public function handle(Request $request): Stringable|string
    {
        $memoryKey = trim($request->string('memory_key')->toString());

        if ($memoryKey === '') {
            return $this->json([
                'success' => false,
                'error' => 'memory_key is required.',
            ]);
        }

        $memory = $this->memories->remember($this->context->dashboard, [
            'memory_key' => $memoryKey,
            'category' => $request->string('category')->toString() ?: 'general',
            'agent_flow' => 'connector_builder',
            'title' => $request->string('title')->toString() ?: Str::headline(str_replace(':', ' ', $memoryKey)),
            'content' => $request->string('content')->toString(),
            'source_tool' => self::class,
            'metadata' => $request->array('metadata') ?: null,
        ], $this->context->user);

        if ($memory === null) {
            return $this->json([
                'success' => false,
                'error' => 'Dashboard memory is disabled.',
            ]);
        }

        return $this->json([
            'success' => true,
            'memory_key' => $memory->memory_key,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'memory_key' => $schema->string()->required(),
            'category' => $schema->string(),
            'title' => $schema->string(),
            'content' => $schema->string()->required(),
            'metadata' => $schema->object([]),
        ];
    }
}
