<?php

namespace App\Console\Commands;

use App\Services\Ingestion\ConnectorApiLogService;
use Illuminate\Console\Command;

class PruneConnectorApiLogsCommand extends Command
{
    protected $signature = 'titan:prune-connector-api-logs';

    protected $description = 'Delete connector API logs older than the configured retention window';

    public function handle(ConnectorApiLogService $logs): int
    {
        $deleted = $logs->pruneExpired();
        $hours = (int) config('titan.connector_api_logs.retention_hours', 48);

        $this->info("Removed {$deleted} connector API log(s) older than {$hours} hours.");

        return self::SUCCESS;
    }
}
