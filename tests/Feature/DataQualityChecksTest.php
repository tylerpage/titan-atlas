<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\SyncStatus;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\DataQualityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DataQualityChecksTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboardWithOrders(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
            'last_synced_at' => now(),
        ]);

        SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => SyncStatus::Success,
            'records_fetched' => 10,
            'records_written' => 10,
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);

        foreach (['2025-06-01', '2025-06-02', '2025-06-03'] as $i => $date) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'resource_type' => 'order',
                'external_id' => (string) (1000 + $i),
                'payload' => ['date' => $date, 'total' => 100 + $i],
                'payload_hash' => hash('sha256', (string) (1000 + $i)),
                'fetched_at' => now(),
            ]);
        }

        return compact('dashboard', 'connection');
    }

    public function test_healthy_dashboard_passes_quality_checks(): void
    {
        ['dashboard' => $dashboard] = $this->createDashboardWithOrders();

        $result = app(DataQualityService::class)->runChecks($dashboard);

        $this->assertTrue($result['summary']['healthy']);
        $this->assertGreaterThan(0, $result['summary']['total_checks']);
    }

    public function test_failed_sync_surfaces_error(): void
    {
        ['dashboard' => $dashboard, 'connection' => $connection] = $this->createDashboardWithOrders();

        $connection->syncRuns()->latest()->first()?->update([
            'status' => SyncStatus::Failed,
            'error_message' => 'API rate limited',
        ]);

        $result = app(DataQualityService::class)->runChecks($dashboard);

        $errors = collect($result['checks'])->where('severity', 'error');
        $this->assertTrue($errors->isNotEmpty());
    }
}
