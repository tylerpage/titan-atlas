<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Models\User;
use App\Support\MetricDimensions;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminConnectionCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_connection(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createConnection();

        $this->actingAs($admin)
            ->get(route('admin.connections.show', $connection))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Connections/Show')
                ->where('connection.name', 'Shopify Store')
            );
    }

    public function test_admin_can_update_connection(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'shop' => ['name' => 'Demo Store'],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createConnection();

        $this->actingAs($admin)
            ->post(route('admin.connections.update', $connection), [
                'name' => 'Updated Store',
                'is_active' => false,
                'credentials' => [],
            ])
            ->assertRedirect(route('admin.connections.show', $connection));

        $connection->refresh();

        $this->assertSame('Updated Store', $connection->name);
        $this->assertFalse($connection->is_active);
    }

    public function test_admin_can_update_connection_credentials(): void
    {
        Http::fake([
            'new-store.myshopify.com/*' => Http::response([
                'shop' => ['name' => 'New Store'],
            ], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createConnection();

        $this->actingAs($admin)
            ->post(route('admin.connections.update', $connection), [
                'name' => 'Shopify Store',
                'is_active' => true,
                'credentials' => [
                    'shop_domain' => 'new-store.myshopify.com',
                ],
            ])
            ->assertRedirect(route('admin.connections.show', $connection));

        $this->assertSame('new-store.myshopify.com', $connection->fresh()->credentials()['shop_domain']);
        $this->assertSame('demo-token', $connection->fresh()->credentials()['access_token']);
    }

    public function test_admin_can_delete_connection_and_metrics(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createConnection();
        $dimensions = ['connection_id' => $connection->id];

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $connection->client_dashboard_id,
            'snapshot_date' => now()->toDateString(),
            'metric_key' => 'revenue',
            'metric_value' => 100,
            'currency' => 'USD',
            'dimensions' => $dimensions,
            'dimension_hash' => MetricDimensions::hash($dimensions),
        ]);

        $this->actingAs($admin)
            ->delete(route('admin.connections.destroy', $connection))
            ->assertRedirect(route('admin.dashboards.show', $connection->client_dashboard_id));

        $this->assertDatabaseMissing('connections', ['id' => $connection->id]);
        $this->assertDatabaseCount('metric_snapshots', 0);
    }

    public function test_admin_can_clear_connection_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createConnection();
        $dimensions = ['connection_id' => $connection->id];

        $connection->update([
            'sync_status' => SyncStatus::Success,
            'last_synced_at' => now(),
            'backfill_completed_at' => now(),
        ]);

        SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => SyncStatus::Success,
            'records_fetched' => 5,
            'records_written' => 5,
            'started_at' => now()->subMinute(),
            'finished_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1001',
            'payload' => ['date' => now()->toDateString(), 'total' => 100],
            'payload_hash' => hash('sha256', '1001'),
            'fetched_at' => now(),
        ]);

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $connection->client_dashboard_id,
            'snapshot_date' => now()->toDateString(),
            'metric_key' => 'revenue',
            'metric_value' => 100,
            'currency' => 'USD',
            'dimensions' => $dimensions,
            'dimension_hash' => MetricDimensions::hash($dimensions),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.connections.clear-data', $connection))
            ->assertRedirect()
            ->assertSessionHas('status');

        $connection->refresh();

        $this->assertDatabaseHas('connections', ['id' => $connection->id]);
        $this->assertDatabaseCount('raw_connector_payloads', 0);
        $this->assertDatabaseCount('sync_runs', 0);
        $this->assertDatabaseCount('metric_snapshots', 0);
        $this->assertSame(SyncStatus::Pending, $connection->sync_status);
        $this->assertNull($connection->last_synced_at);
        $this->assertNull($connection->backfill_completed_at);
    }

    public function test_admin_cannot_clear_data_while_sync_is_running(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $connection = $this->createConnection();
        $connection->update(['sync_status' => SyncStatus::Running]);

        $this->actingAs($admin)
            ->post(route('admin.connections.clear-data', $connection))
            ->assertRedirect()
            ->assertSessionHasErrors('connection');
    }

    public function test_client_cannot_manage_connections(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $connection = $this->createConnection();

        $this->actingAs($client)
            ->get(route('admin.connections.show', $connection))
            ->assertForbidden();

        $this->actingAs($client)
            ->delete(route('admin.connections.destroy', $connection))
            ->assertForbidden();

        $this->actingAs($client)
            ->post(route('admin.connections.clear-data', $connection))
            ->assertForbidden();
    }

    protected function createConnection(): Connection
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify Store',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => [
                'shop_domain' => 'demo.myshopify.com',
                'access_token' => 'demo-token',
            ],
        ]);
    }
}
