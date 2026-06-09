<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLineItemTransformTest extends TestCase
{
    use RefreshDatabase;

    public function test_transform_creates_units_sold_and_line_revenue_metrics(): void
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
        ]);

        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => 'running',
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'order_line_item',
            'external_id' => '1001:9001',
            'payload' => [
                'date' => '2024-06-01',
                'sku' => 'TEE-1',
                'name' => 'Classic Tee',
                'quantity' => 2,
                'line_total' => 80,
                'source' => 'google',
                'medium' => 'cpc',
            ],
            'payload_hash' => hash('sha256', 'line'),
            'fetched_at' => now(),
        ]);

        $written = app(TransformConnectionDataService::class)->transform($syncRun->fresh(['connection.clientDashboard']));

        $this->assertGreaterThan(0, $written);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'units_sold',
            'metric_value' => 2,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'line_revenue',
            'metric_value' => 80,
        ]);

        $snapshot = MetricSnapshot::query()
            ->where('metric_key', 'line_revenue')
            ->first();

        $this->assertSame('TEE-1', $snapshot->dimensions['sku']);
        $this->assertSame('Classic Tee', $snapshot->dimensions['name']);
    }
}
