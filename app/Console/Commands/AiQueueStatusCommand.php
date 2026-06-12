<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AiQueueStatusCommand extends Command
{
    protected $signature = 'titan:ai-queue-status';

    protected $description = 'Show pending AI queue depth, oldest job age, and failed job counts';

    public function handle(): int
    {
        $queueName = (string) config('titan.queues.ai', 'ai');
        $connection = config('queue.default', 'database');

        $this->info("Queue connection: {$connection}");
        $this->info("AI queue name: {$queueName}");
        $this->newLine();

        if ($connection !== 'database') {
            $this->warn('Pending job depth is only reported for the database queue driver.');
            $this->warn('Check your queue provider dashboard for Redis/SQS depth.');

            return self::SUCCESS;
        }

        $table = (string) config('queue.connections.database.table', 'jobs');
        $now = now()->getTimestamp();

        $pending = DB::table($table)
            ->where('queue', $queueName)
            ->orderBy('id')
            ->get(['id', 'created_at', 'available_at']);

        $failedCount = DB::table('failed_jobs')->count();

        $oldestAgeSeconds = null;

        if ($pending->isNotEmpty()) {
            $oldest = $pending->first();
            $oldestTimestamp = (int) ($oldest->available_at ?? strtotime((string) $oldest->created_at));
            $oldestAgeSeconds = max(0, $now - $oldestTimestamp);
        }

        $retryAfter = (int) config('queue.connections.database.retry_after', 90);
        $reportingTimeout = (int) config('titan.reporting.response_timeout_seconds', 120);
        $builderTimeout = (int) config('titan.connector_builder.response_timeout_seconds', 180);

        $this->table(
            ['Metric', 'Value'],
            [
                ['Pending AI jobs', (string) $pending->count()],
                ['Oldest pending job age (s)', $oldestAgeSeconds !== null ? (string) $oldestAgeSeconds : 'n/a'],
                ['Failed jobs (all queues)', (string) $failedCount],
                ['DB retry_after (s)', (string) $retryAfter],
                ['Reporting job timeout (s)', (string) $reportingTimeout],
                ['Connector builder timeout (s)', (string) $builderTimeout],
            ],
        );

        if ($retryAfter < max($reportingTimeout, $builderTimeout)) {
            $this->newLine();
            $this->error('DB_QUEUE_RETRY_AFTER is lower than AI job timeouts.');
            $this->line('Set DB_QUEUE_RETRY_AFTER to at least 210 to avoid duplicate in-flight jobs.');
        }

        if ($pending->count() > 0 && $oldestAgeSeconds !== null && $oldestAgeSeconds > 5) {
            $this->newLine();
            $this->warn('AI jobs are waiting. Confirm a dedicated worker is running:');
            $this->line('php artisan queue:work --queue=ai --timeout=210 --memory=512 --tries=2');
        }

        return self::SUCCESS;
    }
}
