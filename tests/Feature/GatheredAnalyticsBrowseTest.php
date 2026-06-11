<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GatheredAnalyticsBrowseTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_browse_and_sort_raw_payloads(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$dashboard, $connection] = $this->createConnection();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'orders',
            'external_id' => 'order-a',
            'payload' => ['total' => 10, 'date' => '2026-06-01'],
            'payload_date' => '2026-06-01',
            'payload_hash' => hash('sha256', 'order-a'),
            'fetched_at' => now()->subDay(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'orders',
            'external_id' => 'order-b',
            'payload' => ['total' => 20, 'date' => '2026-06-02'],
            'payload_date' => '2026-06-02',
            'payload_hash' => hash('sha256', 'order-b'),
            'fetched_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.gathered-analytics.index', [
                'connection_id' => $connection->id,
                'sort' => 'payload_date',
                'direction' => 'asc',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/GatheredAnalytics/Index')
                ->where('view', 'payloads')
                ->where('records.data.0.external_id', 'order-a')
                ->where('records.data.1.external_id', 'order-b'));
    }

    public function test_admin_can_view_payload_detail(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [, $connection] = $this->createConnection();

        $payload = RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'orders',
            'external_id' => 'order-detail',
            'payload' => ['total' => 99, 'date' => '2026-06-03'],
            'payload_date' => '2026-06-03',
            'payload_hash' => hash('sha256', 'order-detail'),
            'fetched_at' => now(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.gathered-analytics.payloads.show', $payload))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/GatheredAnalytics/PayloadShow')
                ->where('payload.external_id', 'order-detail')
                ->where('payload.formatted_payload', fn ($json) => str_contains($json, '"total": 99')));
    }

    public function test_admin_can_browse_metric_snapshots(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [$dashboard, $connection] = $this->createConnection();

        $metric = MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2026-06-01',
            'metric_key' => 'revenue',
            'metric_value' => 150.25,
            'currency' => 'USD',
            'dimensions' => ['connection_id' => $connection->id],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.gathered-analytics.index', [
                'view' => 'metrics',
                'dashboard_id' => $dashboard->id,
                'metric_key' => 'revenue',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/GatheredAnalytics/Index')
                ->where('view', 'metrics')
                ->has('records.data', 1)
                ->where('records.data.0.metric_key', 'revenue'));

        $this->actingAs($admin)
            ->get(route('admin.gathered-analytics.metrics.show', $metric))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/GatheredAnalytics/MetricShow'));
    }

    /**
     * @return array{0: ClientDashboard, 1: Connection}
     */
    protected function createConnection(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-analytics-browse']);
        $dashboard = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Main', 'slug' => 'main-analytics-browse']);
        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['access_token' => 'token'],
        ]);

        return [$dashboard, $connection];
    }
}
