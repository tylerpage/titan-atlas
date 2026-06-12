<?php

namespace App\Services\AI;

use App\Models\AiAgentTrace;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Tools\ToolNameResolver;

class AiTraceService
{
    /**
     * @param  array<string, mixed>  $snapshot
     */
    public function recordFromAgentResponse(
        string $invocationId,
        AgentResponse $response,
        array $snapshot,
    ): ?AiAgentTrace {
        if (! \App\Support\AiTraceContext::enabled()) {
            return null;
        }

        $usage = $response->usage->toArray();
        $stepsCount = $response->steps->count();

        $payload = [
            'flow' => (string) ($snapshot['flow'] ?? 'unknown'),
            'session_id' => (int) ($snapshot['session_id'] ?? 0),
            'invocation_id' => $invocationId,
            'model' => $response->meta->model ?? ($snapshot['model'] ?? null),
            'agent_class' => (string) ($snapshot['agent_class'] ?? class_basename(AgentResponse::class)),
            'total_ms' => (int) ($snapshot['total_ms'] ?? 0),
            'queue_wait_ms' => isset($snapshot['queue_wait_ms']) ? (int) $snapshot['queue_wait_ms'] : null,
            'tool_ms' => (int) ($snapshot['tool_ms'] ?? 0),
            'estimated_llm_ms' => (int) ($snapshot['estimated_llm_ms'] ?? 0),
            'steps_count' => $stepsCount,
            'prompt_tokens' => (int) ($usage['prompt_tokens'] ?? 0),
            'completion_tokens' => (int) ($usage['completion_tokens'] ?? 0),
            'cache_read_tokens' => (int) ($usage['cache_read_input_tokens'] ?? 0),
            'tools_json' => $snapshot['tools'] ?? [],
            'instructions_chars' => isset($snapshot['instructions_chars']) ? (int) $snapshot['instructions_chars'] : null,
            'history_messages' => isset($snapshot['history_messages']) ? (int) $snapshot['history_messages'] : null,
            'max_steps' => isset($snapshot['max_steps']) ? (int) $snapshot['max_steps'] : null,
        ];

        Log::info('titan_ai.trace', [
            'flow' => $payload['flow'],
            'session_id' => $payload['session_id'],
            'invocation_id' => $invocationId,
            'model' => $payload['model'],
            'agent_class' => $payload['agent_class'],
            'total_ms' => $payload['total_ms'],
            'queue_wait_ms' => $payload['queue_wait_ms'],
            'tool_ms' => $payload['tool_ms'],
            'estimated_llm_ms' => $payload['estimated_llm_ms'],
            'steps_count' => $payload['steps_count'],
            'max_steps' => $payload['max_steps'],
            'prompt_tokens' => $payload['prompt_tokens'],
            'completion_tokens' => $payload['completion_tokens'],
            'cache_read_tokens' => $payload['cache_read_tokens'],
            'instructions_chars' => $payload['instructions_chars'],
            'history_messages' => $payload['history_messages'],
            'tools' => $payload['tools_json'],
        ]);

        return AiAgentTrace::query()->create($payload);
    }

    public static function toolName(object $tool): string
    {
        return ToolNameResolver::resolve($tool);
    }
}
