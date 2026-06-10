<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBuilderSession;
use App\Models\User;
use App\Services\ConnectorBuilder\AiConnectorService;
use App\Services\ConnectorBuilder\ConnectorBlueprintService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
