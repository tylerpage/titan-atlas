<?php

namespace App\Console\Commands;

use App\Enums\SyncRunType;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\Connection;
use Illuminate\Console\Command;

class SyncConnectionsCommand extends Command
{
    protected $signature = 'titan:sync-connections {--type=incremental : backfill, incremental, or today_hourly}';

    protected $description = 'Queue sync jobs for all active dashboard connections';

    public function handle(): int
    {
        $type = SyncRunType::from($this->option('type'));
        $count = 0;

        Connection::query()
            ->where('is_active', true)
            ->with('clientDashboard')
            ->orderBy('id')
            ->each(function (Connection $connection) use ($type, &$count): void {
                SyncConnectionJob::dispatch($connection, $type);
                $count++;
            });

        $this->info("Queued {$count} {$type->value} sync job(s).");

        return self::SUCCESS;
    }
}
