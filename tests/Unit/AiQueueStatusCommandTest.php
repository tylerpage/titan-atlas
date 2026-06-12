<?php

namespace Tests\Unit;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AiQueueStatusCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_reports_pending_ai_jobs_and_retry_after_warning(): void
    {
        config([
            'queue.default' => 'database',
            'titan.queues.ai' => 'ai',
            'queue.connections.database.retry_after' => 90,
            'titan.reporting.response_timeout_seconds' => 120,
            'titan.connector_builder.response_timeout_seconds' => 180,
        ]);

        DB::table('jobs')->insert([
            'queue' => 'ai',
            'payload' => '{}',
            'attempts' => 0,
            'reserved_at' => null,
            'available_at' => now()->subSeconds(12)->getTimestamp(),
            'created_at' => now()->subSeconds(12)->getTimestamp(),
        ]);

        $this->artisan('titan:ai-queue-status')
            ->expectsOutputToContain('Pending AI jobs')
            ->expectsOutputToContain('DB_QUEUE_RETRY_AFTER is lower than AI job timeouts.')
            ->assertSuccessful();
    }

    public function test_reports_no_pending_jobs_on_idle_queue(): void
    {
        config([
            'queue.default' => 'database',
            'titan.queues.ai' => 'ai',
            'queue.connections.database.retry_after' => 210,
        ]);

        $this->artisan('titan:ai-queue-status')
            ->expectsOutputToContain('Pending AI jobs')
            ->doesntExpectOutputToContain('DB_QUEUE_RETRY_AFTER is lower than AI job timeouts.')
            ->assertSuccessful();
    }
}
