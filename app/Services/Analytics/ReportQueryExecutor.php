<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class ReportQueryExecutor
{
    /** @var list<string> */
    protected array $allowedTables = [
        'raw_connector_payloads',
        'metric_snapshots',
        'connections',
        'sync_runs',
        'client_dashboards',
    ];

    /** @var list<string> */
    protected array $forbiddenKeywords = [
        'INSERT', 'UPDATE', 'DELETE', 'DROP', 'ALTER', 'CREATE', 'REPLACE',
        'TRUNCATE', 'PRAGMA', 'ATTACH', 'DETACH', 'VACUUM', 'REINDEX',
    ];

    /**
     * @return array{rows: list<array<string, mixed>>, columns: list<string>, row_count: int}
     */
    public function execute(string $sql, ReportQueryContext $context): array
    {
        $this->validate($sql);

        $maxRows = (int) config('titan.reporting.max_rows', 500);
        $timeout = (int) config('titan.reporting.query_timeout_seconds', 10);

        $wrappedSql = "SELECT * FROM ({$sql}) AS report_query LIMIT {$maxRows}";
        $bindings = $this->bindingsForSql($sql, $context);

        $start = microtime(true);

        $rows = DB::select($wrappedSql, $bindings);

        if ((microtime(true) - $start) > $timeout) {
            throw new InvalidArgumentException('Query exceeded the allowed execution time.');
        }

        $normalized = array_map(fn ($row) => (array) $row, $rows);
        $columns = $normalized !== [] ? array_keys($normalized[0]) : [];

        return [
            'rows' => $normalized,
            'columns' => $columns,
            'row_count' => count($normalized),
        ];
    }

    public function validate(string $sql): void
    {
        $trimmed = trim($sql);

        if ($trimmed === '') {
            throw new InvalidArgumentException('SQL query cannot be empty.');
        }

        if (str_contains($trimmed, ';')) {
            throw new InvalidArgumentException('Multi-statement queries are not allowed.');
        }

        if (preg_match('/--|\/\*|\*\//', $trimmed)) {
            throw new InvalidArgumentException('SQL comments are not allowed.');
        }

        $upper = strtoupper($trimmed);

        if (! str_starts_with($upper, 'SELECT')) {
            throw new InvalidArgumentException('Only SELECT queries are allowed.');
        }

        foreach ($this->forbiddenKeywords as $keyword) {
            if (preg_match('/\b'.preg_quote($keyword, '/').'\b/', $upper)) {
                throw new InvalidArgumentException("Forbidden SQL keyword: {$keyword}");
            }
        }

        $this->validateTables($trimmed);
        $this->validateDashboardScope($trimmed);
    }

    protected function validateTables(string $sql): void
    {
        preg_match_all('/\bFROM\s+([a-z_][a-z0-9_]*)/i', $sql, $fromMatches);
        preg_match_all('/\bJOIN\s+([a-z_][a-z0-9_]*)/i', $sql, $joinMatches);

        $tables = array_merge($fromMatches[1] ?? [], $joinMatches[1] ?? []);

        foreach ($tables as $table) {
            $table = strtolower($table);

            if (! in_array($table, $this->allowedTables, true)) {
                throw new InvalidArgumentException("Table not allowed: {$table}");
            }
        }
    }

    protected function validateDashboardScope(string $sql): void
    {
        $hasDashboardFilter = str_contains($sql, ':dashboard_id')
            || preg_match('/client_dashboard_id\s*=/i', $sql);

        if (! $hasDashboardFilter) {
            throw new InvalidArgumentException('Query must scope data to the dashboard using :dashboard_id or client_dashboard_id.');
        }
    }

    /**
     * @return array<string, int|string>
     */
    protected function bindingsForSql(string $sql, ReportQueryContext $context): array
    {
        return array_filter(
            $context->bindings(),
            fn ($value, $key) => str_contains($sql, ':'.$key),
            ARRAY_FILTER_USE_BOTH,
        );
    }
}
