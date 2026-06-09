<?php

namespace App\Console\Commands;

use App\Models\Connection;
use App\Services\Analytics\TransformConnectionDataService;
use App\Services\Ingestion\RawConnectorPayloadDeduper;
use Illuminate\Console\Command;

class DedupeConnectorPayloadsCommand extends Command
{
    protected $signature = 'titan:dedupe-payloads
                            {--connection= : Connection ID to dedupe (defaults to all)}
                            {--rebuild-metrics : Rebuild metric snapshots after deduping}';

    protected $description = 'Remove duplicate raw connector payloads and optionally rebuild metrics';

    public function handle(
        RawConnectorPayloadDeduper $deduper,
        TransformConnectionDataService $transformer,
    ): int {
        $connectionId = $this->option('connection');
        $rebuildMetrics = (bool) $this->option('rebuild-metrics');
        $deleted = 0;

        if ($connectionId) {
            $connection = Connection::query()->findOrFail($connectionId);
            $deleted = $deduper->dedupeForConnection($connection);
            $this->info("Removed {$deleted} duplicate payload(s) for connection {$connection->id}.");

            if ($rebuildMetrics) {
                $written = $transformer->rebuildForConnection($connection);
                $this->info("Rebuilt {$written} metric snapshot(s) for connection {$connection->id}.");
            }

            return self::SUCCESS;
        }

        $deleted = $deduper->dedupeAll();
        $this->info("Removed {$deleted} duplicate payload(s) across all connections.");

        if ($rebuildMetrics) {
            Connection::query()->orderBy('id')->each(function (Connection $connection) use ($transformer): void {
                $written = $transformer->rebuildForConnection($connection);
                $this->line("Connection {$connection->id}: rebuilt {$written} metric snapshot(s).");
            });
        }

        return self::SUCCESS;
    }
}
