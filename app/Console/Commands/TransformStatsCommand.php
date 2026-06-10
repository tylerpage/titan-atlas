<?php

namespace App\Console\Commands;

use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class TransformStatsCommand extends Command
{
    protected $signature = 'titan:transform-stats
                            {--connection= : Connection ID (defaults to all)}';

    protected $description = 'Report transform backlog and payload/metric counts per connection';

    public function handle(): int
    {
        $connectionId = $this->option('connection');
        $connections = $connectionId
            ? Connection::query()->whereKey($connectionId)->get()
            : Connection::query()->orderBy('id')->get();

        if ($connections->isEmpty()) {
            $this->warn('No connections found.');

            return self::FAILURE;
        }

        $payloadsPerJob = max(1, (int) config('titan.transform.payloads_per_chunk', 750))
            * max(1, (int) config('titan.transform.chunks_per_job', 3));

        foreach ($connections as $connection) {
            $this->line("<fg=cyan>Connection #{$connection->id}</> ({$connection->connector_type->value}) — {$connection->name}");

            $maxPayloadId = (int) RawConnectorPayload::query()
                ->where('connection_id', $connection->id)
                ->max('id');

            $lastTransformed = $connection->last_transformed_payload_id;
            $backlog = $lastTransformed === null
                ? RawConnectorPayload::query()->where('connection_id', $connection->id)->count()
                : RawConnectorPayload::query()
                    ->where('connection_id', $connection->id)
                    ->where('id', '>', $lastTransformed)
                    ->count();

            $estimatedJobs = $backlog > 0 ? (int) ceil($backlog / $payloadsPerJob) : 0;

            $this->table(
                ['Metric', 'Value'],
                [
                    ['Max payload id', (string) $maxPayloadId],
                    ['Last transformed payload id', $lastTransformed === null ? '—' : (string) $lastTransformed],
                    ['Incremental backlog (payloads)', (string) $backlog],
                    ['Est. remaining transform jobs', (string) $estimatedJobs],
                    ['Payloads per job (config)', (string) $payloadsPerJob],
                ],
            );

            $payloadCounts = RawConnectorPayload::query()
                ->select('resource_type', DB::raw('COUNT(*) as count'))
                ->where('connection_id', $connection->id)
                ->groupBy('resource_type')
                ->orderByDesc('count')
                ->get();

            if ($payloadCounts->isNotEmpty()) {
                $this->line('  Raw payloads by resource_type:');
                foreach ($payloadCounts as $row) {
                    $this->line("    {$row->resource_type}: {$row->count}");
                }
            }

            $metricCounts = MetricSnapshot::query()
                ->select('metric_key', DB::raw('COUNT(*) as count'))
                ->where('client_dashboard_id', $connection->client_dashboard_id)
                ->groupBy('metric_key')
                ->orderByDesc('count')
                ->limit(15)
                ->get();

            if ($metricCounts->isNotEmpty()) {
                $this->line('  Metric snapshots by metric_key (dashboard):');
                foreach ($metricCounts as $row) {
                    $this->line("    {$row->metric_key}: {$row->count}");
                }
            }

            $this->newLine();
        }

        return self::SUCCESS;
    }
}
