<?php

namespace Tests\Feature;

use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Services\Analytics\ReportQueryContext;
use App\Services\Analytics\ReportQueryExecutor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportQueryExecutorTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboardWithOrder(float $total = 100, string $date = '2025-06-01'): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Store',
            'connector_type' => 'bigcommerce',
            'encrypted_credentials' => encrypt('{}'),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1',
            'payload' => [
                'date' => $date,
                'total' => $total,
                'order_number' => '#1001',
                'source' => 'google',
                'medium' => 'cpc',
            ],
            'fetched_at' => now(),
        ]);

        return [$dashboard, $connection];
    }

    public function test_executes_parameterized_select_with_date_bindings(): void
    {
        [$dashboard] = $this->createDashboardWithOrder(150, '2025-06-15');

        $executor = app(ReportQueryExecutor::class);
        $context = new ReportQueryContext(
            dashboardId: $dashboard->id,
            startDate: Carbon::parse('2025-06-01'),
            endDate: Carbon::parse('2025-06-30'),
        );

        $sql = <<<'SQL'
SELECT COUNT(*) AS orders, COALESCE(SUM(CAST(json_extract(r.payload, '$.total') AS REAL)), 0) AS revenue
FROM raw_connector_payloads r
JOIN connections c ON c.id = r.connection_id
WHERE c.client_dashboard_id = :dashboard_id
  AND r.resource_type = 'order'
  AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date
SQL;

        $result = $executor->execute($sql, $context);

        $this->assertSame(1, $result['row_count']);
        $this->assertEquals(150.0, $result['rows'][0]['revenue']);
        $this->assertEquals(1, $result['rows'][0]['orders']);
    }

    public function test_rejects_destructive_sql(): void
    {
        [$dashboard] = $this->createDashboardWithOrder();
        $executor = app(ReportQueryExecutor::class);
        $context = new ReportQueryContext(
            dashboardId: $dashboard->id,
            startDate: Carbon::parse('2025-06-01'),
            endDate: Carbon::parse('2025-06-30'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $executor->execute('DELETE FROM connections WHERE client_dashboard_id = :dashboard_id', $context);
    }

    public function test_rejects_queries_without_dashboard_scope(): void
    {
        $executor = app(ReportQueryExecutor::class);
        $context = new ReportQueryContext(
            dashboardId: 1,
            startDate: Carbon::parse('2025-06-01'),
            endDate: Carbon::parse('2025-06-30'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $executor->execute('SELECT * FROM connections', $context);
    }

    public function test_rejects_disallowed_tables(): void
    {
        $executor = app(ReportQueryExecutor::class);
        $context = new ReportQueryContext(
            dashboardId: 1,
            startDate: Carbon::parse('2025-06-01'),
            endDate: Carbon::parse('2025-06-30'),
        );

        $this->expectException(\InvalidArgumentException::class);
        $executor->execute('SELECT * FROM users WHERE client_dashboard_id = :dashboard_id', $context);
    }
}
