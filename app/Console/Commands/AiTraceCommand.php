<?php

namespace App\Console\Commands;

use App\Models\AiAgentTrace;
use Illuminate\Console\Command;

class AiTraceCommand extends Command
{
    protected $signature = 'titan:ai-trace
                            {sessionId : The AI session ID}
                            {--flow=reporting : Flow type: reporting or connector_builder}
                            {--limit=5 : Number of recent traces to show}';

    protected $description = 'Show AI agent performance traces for a session';

    public function handle(): int
    {
        $sessionId = (int) $this->argument('sessionId');
        $flow = (string) $this->option('flow');
        $limit = max(1, (int) $this->option('limit'));

        if (! in_array($flow, ['reporting', 'connector_builder'], true)) {
            $this->error('Invalid flow. Use reporting or connector_builder.');

            return self::FAILURE;
        }

        $traces = AiAgentTrace::query()
            ->where('flow', $flow)
            ->where('session_id', $sessionId)
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get();

        if ($traces->isEmpty()) {
            $this->warn("No AI traces found for {$flow} session {$sessionId}.");

            return self::SUCCESS;
        }

        foreach ($traces as $index => $trace) {
            if ($index > 0) {
                $this->newLine();
            }

            $this->info("Trace #{$trace->id} ({$trace->created_at})");
            $this->table(
                ['Field', 'Value'],
                [
                    ['Invocation', $trace->invocation_id],
                    ['Agent', $trace->agent_class],
                    ['Model', $trace->model ?? 'unknown'],
                    ['Total (ms)', $trace->total_ms],
                    ['Queue wait (ms)', $trace->queue_wait_ms ?? 'n/a'],
                    ['Tool time (ms)', $trace->tool_ms],
                    ['Est. LLM time (ms)', $trace->estimated_llm_ms],
                    ['Steps', "{$trace->steps_count} / ".($trace->max_steps ?? '?')],
                    ['Prompt tokens', $trace->prompt_tokens],
                    ['Completion tokens', $trace->completion_tokens],
                    ['Cache read tokens', $trace->cache_read_tokens],
                    ['Instructions chars', $trace->instructions_chars ?? 'n/a'],
                    ['History messages', $trace->history_messages ?? 'n/a'],
                ],
            );

            $tools = collect($trace->tools_json ?? [])
                ->map(fn (array $tool) => [
                    $tool['name'] ?? 'unknown',
                    $tool['duration_ms'] ?? 0,
                ])
                ->all();

            if ($tools !== []) {
                $this->line('Tools:');
                $this->table(['Tool', 'Duration (ms)'], $tools);
            }
        }

        return self::SUCCESS;
    }
}
