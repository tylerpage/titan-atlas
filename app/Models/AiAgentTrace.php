<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AiAgentTrace extends Model
{
    protected $fillable = [
        'flow',
        'session_id',
        'invocation_id',
        'model',
        'agent_class',
        'total_ms',
        'queue_wait_ms',
        'tool_ms',
        'estimated_llm_ms',
        'steps_count',
        'prompt_tokens',
        'completion_tokens',
        'cache_read_tokens',
        'tools_json',
        'instructions_chars',
        'history_messages',
        'max_steps',
    ];

    protected function casts(): array
    {
        return [
            'tools_json' => 'array',
        ];
    }
}
