<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use App\Services\ConnectorBuilder\AiConnectorService;
use App\Services\ConnectorBuilder\ConnectorBlueprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GlobalAiConnectorCreateTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_start_global_ai_connector_from_library(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-global-create']);
        $dashboard = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Main', 'slug' => 'main-global-create']);

        $this->actingAs($admin)
            ->get(route('admin.ai-connectors.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/AiConnectors/Create')
                ->where('defaultSandboxDashboardId', $dashboard->id));

        $response = $this->actingAs($admin)
            ->post(route('admin.ai-connectors.store'), [
                'sandbox_dashboard_id' => $dashboard->id,
            ]);

        $session = ConnectorBuilderSession::query()->first();

        $this->assertNotNull($session);
        $this->assertTrue((bool) data_get($session->session_config, 'create_as_global'));

        $response
            ->assertRedirect(route('admin.dashboards.connections.ai-create', [$dashboard, $session->id]))
            ->assertSessionHas('status');
    }

    public function test_global_builder_session_saves_blueprint_as_global(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-global-save']);
        $dashboard = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Main', 'slug' => 'main-global-save']);

        $session = app(AiConnectorService::class)->startGlobalBuilder($admin, $dashboard);

        $blueprint = app(ConnectorBlueprintService::class)->upsert(
            dashboard: $dashboard,
            session: $session,
            data: [
                'slug' => 'shopware-admin',
                'label' => 'Shopware Admin',
                'status' => ConnectorBlueprintStatus::Draft->value,
                'auth_config' => ['type' => 'bearer', 'credential_key' => 'access_token'],
                'credential_schema' => [
                    ['key' => 'access_token', 'label' => 'Access token', 'type' => 'password'],
                ],
                'sync_config' => ['base_url' => 'https://api.example.com'],
            ],
        );

        $this->assertTrue($blueprint->isGlobal());
        $this->assertTrue($blueprint->isShared());
        $this->assertNull($blueprint->company_id);
        $this->assertNull($blueprint->client_dashboard_id);
    }

    public function test_resaving_global_blueprint_does_not_scope_it_to_sandbox_dashboard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-global-resave']);
        $dashboard = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Main', 'slug' => 'main-global-resave']);

        $session = app(AiConnectorService::class)->startGlobalBuilder($admin, $dashboard);

        $service = app(ConnectorBlueprintService::class);

        $blueprint = $service->upsert(
            dashboard: $dashboard,
            session: $session,
            data: [
                'slug' => 'example-api',
                'label' => 'Example API',
                'status' => ConnectorBlueprintStatus::Draft->value,
            ],
        );

        $session->refresh();

        $updated = $service->upsert(
            dashboard: $dashboard,
            session: $session,
            data: [
                'slug' => 'example-api',
                'label' => 'Example API Updated',
                'status' => ConnectorBlueprintStatus::Ready->value,
            ],
        );

        $this->assertSame($blueprint->id, $updated->id);
        $this->assertTrue($updated->fresh()->isGlobal());
        $this->assertNull($updated->fresh()->client_dashboard_id);
    }

    public function test_global_blueprint_with_stale_sandbox_dashboard_can_build_on_other_dashboard(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response(['results' => [['id' => '1', 'date' => '2026-06-01']]], 200),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-global-build']);
        $dashboardA = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Sandbox', 'slug' => 'sandbox-global-build']);
        $dashboardB = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Client', 'slug' => 'client-global-build']);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'is_global' => true,
            'client_dashboard_id' => $dashboardA->id,
            'slug' => 'example-global',
            'label' => 'Example Global',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => ['type' => 'bearer', 'credential_key' => 'access_token'],
            'credential_schema' => [
                ['key' => 'access_token', 'label' => 'Access token', 'type' => 'password'],
            ],
            'sync_config' => ['base_url' => 'https://api.example.com', 'test_endpoint' => '/items?limit=1'],
            'dashboard_spec' => [
                'widgets' => [
                    ['title' => 'Total', 'visualization_type' => 'stat_card', 'sql' => 'SELECT 1'],
                ],
            ],
        ]);

        \App\Models\ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'items',
            'resource_type' => 'example_item',
            'path_template' => '/items',
            'response_mapping' => [
                'records_path' => 'results',
                'id_path' => 'id',
                'date_path' => 'date',
            ],
        ]);

        $connection = \App\Models\Connection::query()->create([
            'client_dashboard_id' => $dashboardB->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Example B',
            'connector_type' => \App\Enums\ConnectorType::Dynamic,
            'encrypted_credentials' => ['access_token' => 'token-b'],
        ]);

        \App\Models\RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'example_item',
            'external_id' => 'item-1',
            'payload' => ['id' => '1', 'date' => '2026-06-01'],
            'payload_hash' => hash('sha256', 'item-1'),
            'fetched_at' => now(),
        ]);

        $result = app(\App\Services\ConnectorBuilder\RebuildConnectorDashboardService::class)
            ->rebuild($connection->fresh(['clientDashboard', 'connectorBlueprint.streams']), $admin);

        $this->assertTrue($result['success'] ?? false, $result['error'] ?? 'Rebuild failed');
        $this->assertNull($blueprint->fresh()->client_dashboard_id);
    }
}
