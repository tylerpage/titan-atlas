<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\User;
use App\Services\ConnectorBuilder\AiConnectorService;
use App\Services\ConnectorBuilder\CreateDynamicConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AiConnectorSharingTest extends TestCase
{
    use RefreshDatabase;

    public function test_shared_blueprint_can_back_two_dashboard_connections_with_different_credentials(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response(['results' => [['id' => '1', 'date' => '2026-06-01']]], 200),
        ]);

        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-shared']);
        $dashboardA = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'A', 'slug' => 'a']);
        $dashboardB = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'B', 'slug' => 'b']);

        $blueprint = $this->createSharedBlueprint($company, $dashboardA);

        app(AiConnectorService::class)->share($blueprint);

        $connectionA = app(CreateDynamicConnectionService::class)->create(
            dashboard: $dashboardA,
            blueprint: $blueprint->fresh(),
            name: 'Example A',
            credentials: ['access_token' => 'token-a'],
        );

        $connectionB = app(CreateDynamicConnectionService::class)->create(
            dashboard: $dashboardB,
            blueprint: $blueprint->fresh(),
            name: 'Example B',
            credentials: ['access_token' => 'token-b'],
        );

        $this->assertSame('token-a', $connectionA->credentials()['access_token']);
        $this->assertSame('token-b', $connectionB->credentials()['access_token']);
        $this->assertSame($blueprint->id, $connectionA->connector_blueprint_id);
        $this->assertSame($blueprint->id, $connectionB->connector_blueprint_id);
        $this->assertTrue($blueprint->fresh()->isShared());
        $this->assertSame(2, $blueprint->fresh()->connections()->count());
    }

    public function test_admin_can_open_ai_connector_library_and_connect_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-ui']);
        $dashboard = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Main', 'slug' => 'main']);
        $blueprint = $this->createSharedBlueprint($company, $dashboard);

        app(AiConnectorService::class)->share($blueprint);

        $this->actingAs($admin)
            ->get(route('admin.ai-connectors.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AiConnectors/Index'));

        $this->actingAs($admin)
            ->get(route('admin.dashboards.connections.from-template', [$dashboard, $blueprint]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Connections/ConnectTemplate')
                ->where('blueprint.id', $blueprint->id));
    }

    public function test_global_blueprint_is_available_to_all_companies_with_separate_credentials(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response(['results' => [['id' => '1', 'date' => '2026-06-01']]], 200),
        ]);

        $companyA = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-global-a']);
        $companyB = Company::query()->create(['name' => 'Beta', 'slug' => 'beta-global-b']);
        $dashboardA = ClientDashboard::query()->create(['company_id' => $companyA->id, 'name' => 'A', 'slug' => 'a-global']);
        $dashboardB = ClientDashboard::query()->create(['company_id' => $companyB->id, 'name' => 'B', 'slug' => 'b-global']);

        $blueprint = $this->createSharedBlueprint($companyA, $dashboardA);
        app(AiConnectorService::class)->shareGlobally($blueprint);

        $this->assertTrue($blueprint->fresh()->isGlobal());
        $this->assertTrue(app(AiConnectorService::class)->isAvailableForDashboard($blueprint->fresh(), $dashboardB));

        $templatesForB = app(AiConnectorService::class)->templatesForDashboard($dashboardB);
        $this->assertTrue($templatesForB->contains('id', $blueprint->id));

        $connectionA = app(CreateDynamicConnectionService::class)->create(
            dashboard: $dashboardA,
            blueprint: $blueprint->fresh(),
            name: 'Example A',
            credentials: ['access_token' => 'token-a'],
        );

        $connectionB = app(CreateDynamicConnectionService::class)->create(
            dashboard: $dashboardB,
            blueprint: $blueprint->fresh(),
            name: 'Example B',
            credentials: ['access_token' => 'token-b'],
        );

        $this->assertSame('token-a', $connectionA->credentials()['access_token']);
        $this->assertSame('token-b', $connectionB->credentials()['access_token']);
        $this->assertSame($blueprint->id, $connectionA->connector_blueprint_id);
        $this->assertSame($blueprint->id, $connectionB->connector_blueprint_id);
    }

    public function test_admin_can_share_blueprint_globally_from_library(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-global-ui']);
        $dashboard = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Main', 'slug' => 'main-global']);
        $blueprint = $this->createSharedBlueprint($company, $dashboard);

        $this->actingAs($admin)
            ->post(route('admin.ai-connectors.share-global', $blueprint))
            ->assertRedirect();

        $this->assertTrue($blueprint->fresh()->isGlobal());
        $this->assertTrue($blueprint->fresh()->isShared());
    }

    protected function createSharedBlueprint(Company $company, ClientDashboard $dashboard): ConnectorBlueprint
    {
        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'example-api',
            'label' => 'Example API',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => ['type' => 'bearer', 'credential_key' => 'access_token'],
            'credential_schema' => [
                ['key' => 'access_token', 'label' => 'Access token', 'type' => 'password'],
            ],
            'sync_config' => ['base_url' => 'https://api.example.com', 'test_endpoint' => '/items?limit=1'],
        ]);

        ConnectorBlueprintStream::query()->create([
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

        return $blueprint;
    }
}
