<?php

namespace App\Console\Commands;

use App\Models\ClientDashboard;
use App\Models\Connection;
use App\Support\DedupedRawPayloadQuery;
use App\Support\JsonPayloadSql;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ExplainAnalyticsQueriesCommand extends Command
{
    protected $signature = 'titan:explain-analytics-queries
                            {--connection= : Connection ID for payload/transform queries}
                            {--dashboard= : Client dashboard ID for metric/widget queries}
                            {--start= : Range start date (Y-m-d)}
                            {--end= : Range end date (Y-m-d)}';

    protected $description = 'Run EXPLAIN (ANALYZE, BUFFERS) on representative analytics queries (PostgreSQL only)';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'pgsql') {
            $this->error('This command requires PostgreSQL (current driver: '.DB::getDriverName().').');

            return self::FAILURE;
        }

        $connection = $this->resolveConnection();
        $dashboard = $this->resolveDashboard($connection);
        $start = $this->option('start') ?? now()->subDays(29)->toDateString();
        $end = $this->option('end') ?? now()->toDateString();

        $this->info("Connection #{$connection->id} ({$connection->connector_type->value}), dashboard #{$dashboard->id}");
        $this->info("Date range: {$start} to {$end}");
        $this->newLine();

        $this->explainQuery(
            '1. Dashboard date-range (Search Console daily totals pattern)',
            DedupedRawPayloadQuery::applyToQueryBuilder(
                DB::table('raw_connector_payloads'),
                $connection->id,
                'search_daily',
            )
                ->whereRaw(JsonPayloadSql::dateColumn('payload').' >= ?', [$start])
                ->whereRaw(JsonPayloadSql::dateColumn('payload').' <= ?', [$end])
                ->selectRaw(JsonPayloadSql::dateColumn('payload').' as date')
                ->selectRaw('coalesce(sum('.JsonPayloadSql::real('payload', 'clicks').'), 0) as clicks')
                ->groupByRaw(JsonPayloadSql::dateColumn('payload'))
                ->orderBy('date'),
        );

        $this->explainQuery(
            '2. Transform cursor scan (connection_id + id > ? ORDER BY id LIMIT 250)',
            DedupedRawPayloadQuery::applyToQueryBuilder(
                DB::table('raw_connector_payloads'),
                $connection->id,
            )
                ->where('id', '>', 0)
                ->orderBy('id')
                ->limit(250),
        );

        $this->explainQuery(
            '3. Metric purge by connection (dimensions->>connection_id)',
            DB::table('metric_snapshots')
                ->where('client_dashboard_id', $dashboard->id)
                ->whereRaw(JsonPayloadSql::text('dimensions', 'connection_id').' = ?', [$connection->id]),
            analyze: false,
        );

        $this->explainQuery(
            '4. Widget metric_snapshots date range',
            DB::table('metric_snapshots')
                ->where('client_dashboard_id', $dashboard->id)
                ->whereDate('snapshot_date', '>=', $start)
                ->whereDate('snapshot_date', '<=', $end),
        );

        $this->newLine();
        $this->info('Done. Look for Index Scan or Bitmap Index Scan (not Seq Scan) on hot tables.');

        return self::SUCCESS;
    }

    protected function resolveConnection(): Connection
    {
        $connectionId = $this->option('connection');

        if ($connectionId) {
            return Connection::query()->findOrFail($connectionId);
        }

        return Connection::query()->orderBy('id')->firstOrFail();
    }

    protected function resolveDashboard(Connection $connection): ClientDashboard
    {
        $dashboardId = $this->option('dashboard');

        if ($dashboardId) {
            return ClientDashboard::query()->findOrFail($dashboardId);
        }

        return $connection->clientDashboard;
    }

    protected function explainQuery(string $label, $query, bool $analyze = true): void
    {
        $sql = $query->toSql();
        $bindings = $query->getBindings();

        foreach ($bindings as $binding) {
            $value = is_numeric($binding) ? (string) $binding : "'".str_replace("'", "''", (string) $binding)."'";
            $sql = preg_replace('/\?/', $value, $sql, 1);
        }

        $prefix = $analyze ? 'EXPLAIN (ANALYZE, BUFFERS)' : 'EXPLAIN';

        $this->line("<fg=cyan>{$label}</>");
        $this->line($sql);
        $this->newLine();

        $plan = DB::select("{$prefix} {$sql}");

        foreach ($plan as $row) {
            $this->line($row->{'QUERY PLAN'} ?? json_encode($row));
        }

        $this->newLine();
    }
}
