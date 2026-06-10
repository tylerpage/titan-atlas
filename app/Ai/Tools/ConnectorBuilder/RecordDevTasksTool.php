<?php

namespace App\Ai\Tools\ConnectorBuilder;

use App\Services\ConnectorBuilder\ConnectorBlueprintService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Stringable;

class RecordDevTasksTool extends ConnectorBuilderTool
{
    public function __construct(
        ConnectorBuilderAgentContext $context,
        protected ConnectorBlueprintService $blueprints,
    ) {
        parent::__construct($context);
    }

    public function description(): Stringable|string
    {
        return 'Append structured developer handoff tasks when automation cannot complete the integration.';
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

        $tasks = $request->array('tasks');

        if ($tasks === []) {
            return $this->json([
                'success' => false,
                'error' => 'Provide at least one task.',
            ]);
        }

        $normalized = [];

        foreach ($tasks as $task) {
            if (! is_array($task) || empty($task['task'])) {
                continue;
            }

            $normalized[] = [
                'task' => (string) $task['task'],
                'priority' => (string) ($task['priority'] ?? 'medium'),
                'reason' => (string) ($task['reason'] ?? ''),
                'blocked_on' => (string) ($task['blocked_on'] ?? ''),
                'suggested_files' => $task['suggested_files'] ?? [],
                'acceptance_criteria' => (string) ($task['acceptance_criteria'] ?? ''),
            ];
        }

        $blueprint = $this->blueprints->appendDevTasks($this->context->blueprint, $normalized);
        $this->context->blueprint = $blueprint;
        $this->context->lastDevTasks = $normalized;

        return $this->json([
            'success' => true,
            'dev_tasks' => $blueprint->dev_tasks,
            'status' => $blueprint->status->value,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'tasks' => $schema->array()->items($schema->object([
                'task' => $schema->string()->required(),
                'priority' => $schema->string(),
                'reason' => $schema->string(),
                'blocked_on' => $schema->string(),
                'suggested_files' => $schema->array()->items($schema->string()),
                'acceptance_criteria' => $schema->string(),
            ]))->required(),
        ];
    }
}
