<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\User;
use App\Services\ConnectorBuilder\AiConnectorExportService;
use App\Services\ConnectorBuilder\AiConnectorImportService;
use App\Support\AiConnectorPortableFormat;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiConnectorExportImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_download_connector_export(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $blueprint = $this->createShopwareBlueprint(global: true);

        $this->actingAs($admin)
            ->get(route('admin.ai-connectors.export', $blueprint))
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertDownload($blueprint->slug.'-ai-connector.json');
    }

    public function test_export_strips_environment_specific_dashboard_ids(): void
    {
        $blueprint = $this->createShopwareBlueprint(global: true);
        $blueprint->update([
            'dashboard_spec' => [
                'title' => 'Shopware Dashboard',
                'saved_dashboard_id' => 99,
                'created_report_ids' => [1, 2, 3],
                'client_dashboard_id' => 12,
                'widgets' => [[
                    'prompt' => 'Total Sales',
                    'sql' => 'SELECT 1',
                    'visualization_type' => 'stat_card',
                    'connection_id' => 7,
                ]],
            ],
        ]);

        $package = app(AiConnectorExportService::class)->export($blueprint->fresh())['package'];

        $this->assertSame('titan-ai-connector', $package['format']);
        $this->assertSame(1, $package['format_version']);
        $this->assertArrayNotHasKey('saved_dashboard_id', $package['blueprint']['dashboard_spec']);
        $this->assertArrayNotHasKey('created_report_ids', $package['blueprint']['dashboard_spec']);
        $this->assertArrayNotHasKey('connection_id', $package['blueprint']['dashboard_spec']['widgets'][0]);
        $this->assertCount(1, $package['blueprint']['streams']);
    }

    public function test_import_creates_global_connector(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $package = $this->samplePackage();

        $this->actingAs($admin)
            ->post(route('admin.ai-connectors.import.store'), [
                'payload' => json_encode($package),
                'scope' => 'global',
                'mode' => 'create',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $blueprint = ConnectorBlueprint::query()->where('slug', 'shopware-troubleshoot')->first();

        $this->assertNotNull($blueprint);
        $this->assertTrue($blueprint->isGlobal());
        $this->assertSame('Shopware Troubleshoot', $blueprint->label);
        $this->assertCount(1, $blueprint->streams);
        $this->assertSame('order', $blueprint->streams->first()->resource_type);
    }

    public function test_import_create_fails_when_global_slug_exists(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->createShopwareBlueprint(global: true, slug: 'shopware-troubleshoot');

        $this->actingAs($admin)
            ->post(route('admin.ai-connectors.import.store'), [
                'payload' => json_encode($this->samplePackage()),
                'scope' => 'global',
                'mode' => 'create',
            ])
            ->assertSessionHasErrors('mode');
    }

    public function test_import_replace_updates_existing_global_connector(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $existing = $this->createShopwareBlueprint(global: true, slug: 'shopware-troubleshoot', label: 'Old Label');

        $package = $this->samplePackage();
        $package['blueprint']['label'] = 'Updated Label';

        $this->actingAs($admin)
            ->post(route('admin.ai-connectors.import.store'), [
                'payload' => json_encode($package),
                'scope' => 'global',
                'mode' => 'replace',
            ])
            ->assertRedirect(route('admin.ai-connectors.show', $existing));

        $this->assertSame('Updated Label', $existing->fresh()->label);
        $this->assertSame(1, ConnectorBlueprint::query()->where('slug', 'shopware-troubleshoot')->count());
    }

    public function test_import_creates_company_scoped_connector(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-import']);

        $this->actingAs($admin)
            ->post(route('admin.ai-connectors.import.store'), [
                'payload' => json_encode($this->samplePackage()),
                'scope' => 'company',
                'mode' => 'create',
                'company_id' => $company->id,
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $blueprint = ConnectorBlueprint::query()
            ->where('company_id', $company->id)
            ->where('slug', 'shopware-troubleshoot')
            ->first();

        $this->assertNotNull($blueprint);
        $this->assertFalse($blueprint->isGlobal());
        $this->assertNull($blueprint->client_dashboard_id);
    }

    public function test_import_service_rejects_invalid_format(): void
    {
        $this->expectException(\Illuminate\Validation\ValidationException::class);

        app(AiConnectorImportService::class)->import([
            'format' => 'other',
            'format_version' => 1,
            'blueprint' => [],
        ], [
            'scope' => 'global',
            'mode' => 'create',
        ]);
    }

    public function test_admin_can_view_import_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->get(route('admin.ai-connectors.import'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/AiConnectors/Import'));
    }

    /**
     * @return array<string, mixed>
     */
    protected function samplePackage(): array
    {
        return [
            'format' => AiConnectorPortableFormat::FORMAT,
            'format_version' => AiConnectorPortableFormat::VERSION,
            'exported_at' => now()->toIso8601String(),
            'blueprint' => [
                'slug' => 'shopware-troubleshoot',
                'label' => 'Shopware Troubleshoot',
                'status' => ConnectorBlueprintStatus::Ready->value,
                'scope' => 'global',
                'original_prompt' => 'Shopware orders connector',
                'auth_config' => [
                    'type' => 'oauth2_client_credentials',
                    'token_url' => '/api/oauth/token',
                    'grant_type' => 'client_credentials',
                ],
                'credential_schema' => [
                    ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                    ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
                ],
                'sync_config' => [
                    'base_url' => 'https://shop.example.com',
                    'test_endpoint' => '/api/search/order',
                ],
                'transform_config' => [
                    'order' => [
                        'metrics' => [
                            ['key' => 'total', 'value_path' => 'total', 'date_path' => 'date'],
                        ],
                    ],
                ],
                'dashboard_spec' => [
                    'title' => 'Shopware Dashboard',
                    'widgets' => [[
                        'prompt' => 'Total Sales Overview',
                        'sql' => "SELECT COUNT(*) AS total_orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND r.connection_id = :connection_id",
                        'visualization_type' => 'stat_card',
                    ]],
                ],
                'dev_tasks' => [],
                'streams' => [[
                    'stream_key' => 'orders',
                    'resource_type' => 'order',
                    'http_method' => 'POST',
                    'path_template' => '/api/search/order',
                    'request_body' => ['filter' => []],
                    'request_body_format' => 'json',
                    'pagination' => [
                        'type' => 'page',
                        'location' => 'body',
                        'page_param' => 'page',
                        'limit_param' => 'limit',
                        'page_size' => 50,
                    ],
                    'response_mapping' => [
                        'records_path' => 'data',
                        'id_path' => 'id',
                        'date_path' => 'orderDateTime',
                        'fields' => [
                            ['source' => 'amountTotal', 'target' => 'total'],
                            ['source' => 'orderDateTime', 'target' => 'order_date'],
                        ],
                    ],
                    'enabled' => true,
                ]],
            ],
        ];
    }

    protected function createShopwareBlueprint(
        bool $global = false,
        string $slug = 'shopware-troubleshoot',
        string $label = 'Shopware Troubleshoot',
    ): ConnectorBlueprint {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-export-'.$slug]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-export-'.$slug,
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $global ? null : $company->id,
            'is_global' => $global,
            'client_dashboard_id' => $global ? null : $dashboard->id,
            'slug' => $slug,
            'label' => $label,
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => ['type' => 'oauth2_client_credentials'],
            'credential_schema' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
            ],
            'sync_config' => ['base_url' => 'https://shop.example.com'],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'order',
            'path_template' => '/api/search/order',
            'http_method' => 'POST',
            'enabled' => true,
        ]);

        return $blueprint;
    }
}
