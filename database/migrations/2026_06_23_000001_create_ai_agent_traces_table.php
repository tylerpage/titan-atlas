<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_agent_traces', function (Blueprint $table) {
            $table->id();
            $table->string('flow');
            $table->unsignedBigInteger('session_id');
            $table->string('invocation_id');
            $table->string('model')->nullable();
            $table->string('agent_class');
            $table->unsignedInteger('total_ms')->default(0);
            $table->unsignedInteger('queue_wait_ms')->nullable();
            $table->unsignedInteger('tool_ms')->default(0);
            $table->unsignedInteger('estimated_llm_ms')->default(0);
            $table->unsignedSmallInteger('steps_count')->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->json('tools_json')->nullable();
            $table->unsignedInteger('instructions_chars')->nullable();
            $table->unsignedSmallInteger('history_messages')->nullable();
            $table->unsignedSmallInteger('max_steps')->nullable();
            $table->timestamps();

            $table->index(['flow', 'session_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_traces');
    }
};
