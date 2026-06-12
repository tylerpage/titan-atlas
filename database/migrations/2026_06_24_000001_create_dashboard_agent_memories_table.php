<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dashboard_agent_memories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_dashboard_id')->constrained()->cascadeOnDelete();
            $table->string('memory_key');
            $table->string('category');
            $table->string('agent_flow')->default('both');
            $table->string('title');
            $table->text('content');
            $table->string('source_tool')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_used_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['client_dashboard_id', 'memory_key']);
            $table->index(['client_dashboard_id', 'category']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dashboard_agent_memories');
    }
};
