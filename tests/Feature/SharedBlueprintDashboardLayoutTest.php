<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorType;
use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\RawConnectorPayload;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use App\Services\Analytics\DynamicConnectorDashboardService;
use App\Services\ConnectorBuilder\AiConnectorService;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;
use App\Services\ConnectorBuilder\CreateDynamicConnectionService;
use App\Services\ConnectorBuilder\RebuildConnectorDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SharedBlueprintDashboardLayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_blueprint_resolves_layout_per_dashboard(): void
    {
        [$company, $dashboardA, $dashboardB, $blueprint, $boardA] = $this->createSharedShopwareBlueprint();

        $layouts = app(ConnectorBlueprintDashboardVersionService::class);

        $this->assertNotNull($layouts->currentSpec($blueprint, $dashboardA));
        $this->assertNull($layouts->currentSpec($blueprint, $dashboardB));
        $this->assertSame($boardA->id, $layouts->currentSpec($blueprint, $dashboardA)['saved_dashboard_id']);
    }

    public function test_auto_build_creates_layout_when_adding_shared_connector_to_second_dashboard(): void
    {
        Http::fake([
            'https://shop.example.com/*' => Http::response(['data' => []], 200),
        ]);

        [$company, $dashboardA, $dashboardB, $blueprint] = $this->createSharedShopwareBlueprint(withLayoutOnA: true);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        app(AiConnectorService::class)->share($blueprint);

        Connection::query()->create([
            'client_dashboard_id' => $dashboardA->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware A',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'a', 'client_secret' => 'secret'],
        ]);

        $connectionB = app(CreateDynamicConnectionService::class)->create(
            dashboard: $dashboardB,
            blueprint: $blueprint->fresh(),
            name: 'Shopware B',
            credentials: ['client_id' => 'b', 'client_secret' => 'secret'],
            user: $admin,
        );

        RawConnectorPayload::query()->create([
            'connection_id' => $connectionB->id,
            'resource_type' => 'shopware_order',
            'external_id' => 'order-b-1',
            'payload' => [
                'amountTotal' => 88.25,
                'orderDateTime' => now()->subDay()->toIso8601String(),
            ],
            'payload_hash' => hash('sha256', 'order-b-1'),
            'fetched_at' => now(),
        ]);

        $layoutB = app(ConnectorBlueprintDashboardVersionService::class)->currentSpec($blueprint->fresh(), $dashboardB);

        $this->assertNotNull($layoutB);
        $this->assertNotSame(
            app(ConnectorBlueprintDashboardVersionService::class)->currentSpec($blueprint, $dashboardA)['saved_dashboard_id'],
            $layoutB['saved_dashboard_id'],
        );

        $boardB = SavedDashboard::query()
            ->where('client_dashboard_id', $dashboardB->id)
            ->find($layoutB['saved_dashboard_id']);

        $this->assertNotNull($boardB);
    }

    public function test_rebuild_on_second_dashboard_does_not_remove_first_dashboard_layout(): void
    {
        [$company, $dashboardA, $dashboardB, $blueprint, $boardA, $connectionA] = $this->createSharedShopwareBlueprint(withLayoutOnA: true);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        app(AiConnectorService::class)->share($blueprint);

        $connectionB = Connection::query()->create([
            'client_dashboard_id' => $dashboardB->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware B',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'b', 'client_secret' => 'secret'],
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connectionB->id,
            'resource_type' => 'shopware_order',
            'external_id' => 'order-b-1',
            'payload' => [
                'amountTotal' => 50,
                'orderDateTime' => now()->subDay()->toIso8601String(),
            ],
            'payload_hash' => hash('sha256', 'order-b-1'),
            'fetched_at' => now(),
        ]);

        app(RebuildConnectorDashboardService::class)->rebuild($connectionB, $admin);

        $layouts = app(ConnectorBlueprintDashboardVersionService::class);

        $this->assertSame(
            $boardA->id,
            $layouts->currentSpec($blueprint->fresh(), $dashboardA)['saved_dashboard_id'],
        );
        $this->assertNotNull($layouts->currentSpec($blueprint->fresh(), $dashboardB));
    }

    public function test_second_dashboard_data_tab_uses_its_own_saved_board(): void
    {
        [$company, $dashboardA, $dashboardB, $blueprint, $boardA, $connectionA] = $this->createSharedShopwareBlueprint(withLayoutOnA: true);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $clientB = User::factory()->create(['role' => UserRole::Client]);
        $dashboardB->users()->attach($clientB);

        app(AiConnectorService::class)->share($blueprint);

        $connectionB = Connection::query()->create([
            'client_dashboard_id' => $dashboardB->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware B',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'b', 'client_secret' => 'secret'],
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connectionB->id,
            'resource_type' => 'shopware_order',
            'external_id' => 'order-b-1',
            'payload' => [
                'amountTotal' => 200,
                'orderDateTime' => now()->subDay()->toIso8601String(),
            ],
            'payload_hash' => hash('sha256', 'order-b-1'),
            'fetched_at' => now(),
        ]);

        app(RebuildConnectorDashboardService::class)->rebuild($connectionB, $admin);

        $this->actingAs($clientB)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboardB,
                'connection' => $connectionB->id,
                'tab' => 'data',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('connectorData.kind', 'dynamic')
                ->has('connectorData.blocks', 3)
            );
    }

    public function test_connection_show_exposes_build_dashboard_for_shared_connector_without_layout(): void
    {
        [$company, $dashboardA, $dashboardB, $blueprint] = $this->createSharedShopwareBlueprint(withLayoutOnA: false);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        app(AiConnectorService::class)->share($blueprint);

        $connectionB = Connection::query()->create([
            'client_dashboard_id' => $dashboardB->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware B',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'b'],
        ]);

        $this->actingAs($admin)
            ->get(route('admin.connections.show', $connectionB))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('connection.can_build_dashboard', true)
                ->where('connection.has_dashboard_layout', false)
            );
    }

    /**
     * @return array{0: Company, 1: ClientDashboard, 2: ClientDashboard, 3: ConnectorBlueprint, 4?: SavedDashboard, 5?: Connection}
     */
    protected function createSharedShopwareBlueprint(bool $withLayoutOnA = true): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-shared-layout']);
        $dashboardA = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Dashboard A',
            'slug' => 'dashboard-a',
        ]);
        $dashboardB = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Dashboard B',
            'slug' => 'dashboard-b',
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $countSql = "SELECT COUNT(*) AS total_orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'shopware_order' AND r.connection_id = :connection_id";

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'client_dashboard_id' => $dashboardA->id,
            'slug' => 'shopware-shared',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Active,
            'sync_config' => [
                'base_url' => 'https://shop.example.com',
                'test_endpoint' => '/api/search/order',
            ],
            'dashboard_spec' => [
                'title' => 'Shopware Dashboard',
                'widgets' => [[
                    'prompt' => 'Total orders',
                    'sql' => $countSql,
                    'visualization_type' => 'stat_card',
                ]],
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'shopware_order',
            'path_template' => '/api/search/order',
            'http_method' => 'POST',
            'enabled' => true,
        ]);

        $connectionA = Connection::query()->create([
            'client_dashboard_id' => $dashboardA->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware A',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'a', 'client_secret' => 'secret'],
        ]);

        if (! $withLayoutOnA) {
            return [$company, $dashboardA, $dashboardB, $blueprint];
        }

        RawConnectorPayload::query()->create([
            'connection_id' => $connectionA->id,
            'resource_type' => 'shopware_order',
            'external_id' => 'order-a-1',
            'payload' => [
                'amountTotal' => 125.50,
                'orderDateTime' => now()->subDay()->toIso8601String(),
            ],
            'payload_hash' => hash('sha256', 'order-a-1'),
            'fetched_at' => now(),
        ]);

        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboardA->id,
            'created_by' => $admin->id,
            'prompt' => 'Total Sales Overview',
            'sql' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.amountTotal') AS REAL)), 0) AS total_sales FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'shopware_order' AND r.connection_id = :connection_id",
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [
                'header' => 'Total Sales Overview',
                'value_column' => 'total_sales',
                'format' => 'currency',
                'connection_id' => $connectionA->id,
            ],
        ]);

        $boardA = SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboardA->id,
            'title' => 'Shopware Dashboard',
            'created_by' => $admin->id,
        ]);

        SavedDashboardBlock::query()->create([
            'saved_dashboard_id' => $boardA->id,
            'analytics_report_id' => $report->id,
            'sort_order' => 1,
        ]);

        $layoutSpec = [
            'title' => 'Shopware Dashboard',
            'widgets' => $blueprint->dashboard_spec['widgets'],
            'saved_dashboard_id' => $boardA->id,
            'created_report_ids' => [$report->id],
            'client_dashboard_id' => $dashboardA->id,
        ];

        app(ConnectorBlueprintDashboardVersionService::class)->recordCurrent(
            $blueprint,
            $dashboardA,
            $admin,
            $layoutSpec,
        );

        $blueprint->update([
            'dashboard_spec' => array_merge($blueprint->dashboard_spec ?? [], [
                'saved_dashboard_id' => $boardA->id,
                'created_report_ids' => [$report->id],
                'client_dashboard_id' => $dashboardA->id,
            ]),
        ]);

        return [$company, $dashboardA, $dashboardB, $blueprint, $boardA, $connectionA];
    }
}
