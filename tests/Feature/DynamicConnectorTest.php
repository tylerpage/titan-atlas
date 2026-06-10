<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Ingestion\Connectors\DynamicConnector;
use App\Ingestion\Connectors\Dynamic\DynamicHttpClient;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\ConnectorBuilderSession;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DynamicConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        DynamicHttpClient::resetTokenCache();
    }

    public function test_dynamic_connector_validates_fetches_and_transforms_hubspot_like_blueprint(): void
    {
        Http::fake([
            'https://api.hubapi.com/crm/v3/objects/deals*' => Http::response([
                'results' => [[
                    'id' => '1',
                    'properties' => [
                        'amount' => '5000',
                        'dealstage' => 'closedwon',
                        'createdate' => '2026-06-01T12:00:00Z',
                    ],
                ]],
            ], 200),
            'https://api.hubapi.com/crm/v3/objects/contacts*' => Http::response([
                'results' => [[
                    'id' => '2',
                    'properties' => [
                        'email' => 'user@example.com',
                        'createdate' => '2026-06-02T08:00:00Z',
                    ],
                ]],
            ], 200),
        ]);

        [$dashboard, $blueprint, $connection] = $this->createHubspotLikeSetup();

        $connector = app(DynamicConnector::class);
        $validation = $connector->validateCredentials($connection);

        $this->assertTrue($validation->valid);

        $allRecords = [];
        $cursor = null;

        do {
            $result = $connector->fetch($connection, $cursor);
            $allRecords = array_merge($allRecords, $result->records);
            $cursor = $result->nextCursor;
        } while ($result->hasMore && $cursor !== null);

        $this->assertGreaterThanOrEqual(2, count($allRecords));

        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'backfill',
            'status' => 'running',
        ]);

        foreach ($allRecords as $record) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'sync_run_id' => $syncRun->id,
                'resource_type' => $record['resource_type'],
                'external_id' => $record['external_id'],
                'payload' => $record['payload'],
                'payload_hash' => md5(json_encode($record['payload'])),
                'fetched_at' => now(),
            ]);
        }

        $written = app(TransformConnectionDataService::class)->transform($syncRun)->written;

        $this->assertGreaterThan(0, $written);

        $this->assertTrue(
            MetricSnapshot::query()
                ->where('client_dashboard_id', $dashboard->id)
                ->where('metric_key', 'deal_amount')
                ->exists()
        );
    }

    public function test_blueprint_service_rejects_delete_streams(): void
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-ro'])->id,
            'name' => 'Main',
            'slug' => 'main-ro',
        ]);

        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => User::factory()->create(['role' => UserRole::Admin])->id,
            'status' => ConnectorBuilderSessionStatus::Active,
            'title' => 'HubSpot',
        ]);

        $dashboard->load('company');

        $this->expectException(\InvalidArgumentException::class);

        app(\App\Services\ConnectorBuilder\ConnectorBlueprintService::class)->upsert(
            dashboard: $dashboard,
            session: $session,
            data: [
                'slug' => 'hubspot',
                'label' => 'HubSpot',
                'streams' => [[
                    'stream_key' => 'deals',
                    'http_method' => 'DELETE',
                    'path_template' => '/crm/v3/objects/deals',
                ]],
            ],
        );
    }

    public function test_dynamic_connector_supports_post_read_stream_and_token_auth(): void
    {
        Http::fake([
            'https://api.example.com/oauth/token*' => Http::response([
                'access_token' => 'fresh-token',
            ], 200),
            'https://api.example.com/search*' => Http::response([
                'results' => [[
                    'id' => '42',
                    'amount' => '1200',
                    'date' => '2026-06-01',
                ]],
            ], 200),
        ]);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-post'])->id,
            'name' => 'Main',
            'slug' => 'main-post',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'example',
            'label' => 'Example',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => [
                'type' => 'bearer',
                'token_request' => [
                    'method' => 'POST',
                    'path' => '/oauth/token',
                    'body_format' => 'form',
                    'body' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => '{{client_id}}',
                        'client_secret' => '{{client_secret}}',
                    ],
                    'token_path' => 'access_token',
                ],
            ],
            'credential_schema' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
            ],
            'sync_config' => [
                'base_url' => 'https://api.example.com',
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'search',
            'resource_type' => 'example_record',
            'http_method' => 'POST',
            'path_template' => '/search',
            'request_body' => ['query' => 'recent deals'],
            'response_mapping' => [
                'records_path' => 'results',
                'id_path' => 'id',
                'date_path' => 'date',
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Example',
            'encrypted_credentials' => [
                'client_id' => 'app-id',
                'client_secret' => 'app-secret',
            ],
        ]);

        $connector = app(DynamicConnector::class);
        $validation = $connector->validateCredentials($connection);

        $this->assertTrue($validation->valid);

        $result = $connector->fetch($connection);

        $this->assertCount(1, $result->records);
        $this->assertSame('42', $result->records[0]['external_id']);
    }

    public function test_dynamic_connector_supports_oauth2_client_credentials_auth(): void
    {
        Http::fake([
            'https://shop.example.com/api/oauth/token*' => Http::response([
                'token_type' => 'Bearer',
                'expires_in' => 600,
                'access_token' => 'shopware-token',
            ], 200),
            'https://shop.example.com/api/order*' => Http::response([
                'data' => [[
                    'id' => 'order-1',
                    'orderNumber' => '10001',
                    'orderDateTime' => '2026-06-01T12:00:00+00:00',
                    'amountTotal' => 99.99,
                ]],
            ], 200),
        ]);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Shop Co', 'slug' => 'shop-co'])->id,
            'name' => 'Shopware',
            'slug' => 'shopware',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => [
                'type' => 'oauth2_client_credentials',
                'token_url' => '/api/oauth/token',
            ],
            'credential_schema' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
            ],
            'sync_config' => [
                'base_url' => 'https://shop.example.com',
                'test_endpoint' => '/api/order?limit=1',
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'shopware_order',
            'path_template' => '/api/order',
            'query_params' => ['limit' => 50],
            'response_mapping' => [
                'records_path' => 'data',
                'id_path' => 'id',
                'date_path' => 'orderDateTime',
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware',
            'encrypted_credentials' => [
                'client_id' => 'integration-id',
                'client_secret' => 'integration-secret',
            ],
        ]);

        $connector = app(DynamicConnector::class);
        $validation = $connector->validateCredentials($connection);

        $this->assertTrue($validation->valid);

        $result = $connector->fetch($connection);

        $this->assertCount(1, $result->records);
        $this->assertSame('order-1', $result->records[0]['external_id']);

        Http::assertSent(function ($request) {
            if ($request->url() === 'https://shop.example.com/api/oauth/token') {
                return $request->method() === 'POST'
                    && $request->data()['grant_type'] === 'client_credentials'
                    && $request->data()['client_id'] === 'integration-id'
                    && $request->data()['client_secret'] === 'integration-secret';
            }

            if (str_starts_with($request->url(), 'https://shop.example.com/api/order')) {
                return $request->hasHeader('Authorization', 'Bearer shopware-token');
            }

            return false;
        });
    }

    public function test_connection_probe_uses_post_for_token_even_when_token_request_method_is_get(): void
    {
        Http::fake([
            'https://shop.example.com/api/oauth/token*' => function ($request) {
                if ($request->method() !== 'POST') {
                    return Http::response(['error' => 'Method Not Allowed'], 405);
                }

                return Http::response(['access_token' => 'shopware-token', 'expires_in' => 600], 200);
            },
            'https://shop.example.com/api/order*' => Http::response([
                'data' => [['id' => 'order-1', 'orderDateTime' => '2026-06-01T12:00:00+00:00']],
            ], 200),
        ]);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Shop Co', 'slug' => 'shop-co-probe'])->id,
            'name' => 'Shopware',
            'slug' => 'shopware-probe',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware-probe',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => [
                'type' => 'oauth2_client_credentials',
                'token_url' => '/api/oauth/token',
                'token_request' => [
                    'method' => 'GET',
                ],
            ],
            'credential_schema' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
            ],
            'sync_config' => [
                'base_url' => 'https://shop.example.com',
                'test_endpoint' => '/api/order?limit=1',
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'shopware_order',
            'path_template' => '/api/order',
            'response_mapping' => [
                'records_path' => 'data',
                'id_path' => 'id',
                'date_path' => 'orderDateTime',
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware',
            'encrypted_credentials' => [
                'client_id' => 'integration-id',
                'client_secret' => 'integration-secret',
            ],
        ]);

        $validation = app(DynamicConnector::class)->validateCredentials($connection);

        $this->assertTrue($validation->valid);

        Http::assertSent(fn ($request) => $request->url() === 'https://shop.example.com/api/oauth/token'
            && $request->method() === 'POST');
    }

    public function test_connection_probe_skips_get_on_token_test_endpoint_and_uses_data_stream(): void
    {
        Http::fake([
            'https://shop.example.com/api/oauth/token*' => function ($request) {
                if ($request->method() !== 'POST') {
                    return Http::response(['error' => 'Method Not Allowed'], 405);
                }

                return Http::response(['access_token' => 'shopware-token', 'expires_in' => 600], 200);
            },
            'https://shop.example.com/api/order*' => Http::response([
                'data' => [['id' => 'order-1', 'orderDateTime' => '2026-06-01T12:00:00+00:00']],
            ], 200),
        ]);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Shop Co', 'slug' => 'shop-co-token-test'])->id,
            'name' => 'Shopware',
            'slug' => 'shopware-token-test',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware-token-test',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => [
                'type' => 'oauth2_client_credentials',
                'token_url' => '/api/oauth/token',
            ],
            'credential_schema' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
            ],
            'sync_config' => [
                'base_url' => 'https://shop.example.com',
                'test_endpoint' => '/api/oauth/token',
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'shopware_order',
            'path_template' => '/api/order',
            'response_mapping' => [
                'records_path' => 'data',
                'id_path' => 'id',
                'date_path' => 'orderDateTime',
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware',
            'encrypted_credentials' => [
                'client_id' => 'integration-id',
                'client_secret' => 'integration-secret',
            ],
        ]);

        $validation = app(DynamicConnector::class)->validateCredentials($connection);

        $this->assertTrue($validation->valid);

        Http::assertSent(fn ($request) => $request->url() === 'https://shop.example.com/api/oauth/token'
            && $request->method() === 'POST');
        Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://shop.example.com/api/order')
            && $request->method() === 'GET');
        Http::assertNotSent(fn ($request) => $request->url() === 'https://shop.example.com/api/oauth/token'
            && $request->method() === 'GET');
    }

    public function test_dynamic_connector_supports_shopware_post_search_orders_stream(): void
    {
        Http::fake([
            'https://shop.example.com/api/oauth/token*' => Http::response([
                'access_token' => 'shopware-token',
                'expires_in' => 600,
            ], 200),
            'https://shop.example.com/api/search/order*' => function ($request) {
                if ($request->method() !== 'POST') {
                    return Http::response(['error' => 'Method Not Allowed'], 405);
                }

                return Http::response([
                    'data' => [[
                        'id' => 'order-search-1',
                        'orderDateTime' => '2026-06-02T10:00:00+00:00',
                    ]],
                ], 200);
            },
        ]);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Shop Co', 'slug' => 'shop-co-search'])->id,
            'name' => 'Shopware Search',
            'slug' => 'shopware-search',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware-search',
            'label' => 'Shopware Search',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => [
                'type' => 'oauth2_client_credentials',
                'token_url' => '/api/oauth/token',
            ],
            'credential_schema' => [
                ['key' => 'client_id', 'label' => 'Client ID', 'type' => 'text'],
                ['key' => 'client_secret', 'label' => 'Client Secret', 'type' => 'password'],
            ],
            'sync_config' => [
                'base_url' => 'https://shop.example.com',
                'test_endpoint' => '/api/search/order',
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'shopware_order',
            'http_method' => 'POST',
            'path_template' => '/api/search/order',
            'request_body' => [
                'filter' => [],
                'sort' => [['field' => 'orderDateTime', 'order' => 'DESC']],
            ],
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
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware Search',
            'encrypted_credentials' => [
                'client_id' => 'integration-id',
                'client_secret' => 'integration-secret',
            ],
        ]);

        $connector = app(DynamicConnector::class);
        $validation = $connector->validateCredentials($connection);

        $this->assertTrue($validation->valid);

        $result = $connector->fetch($connection);

        $this->assertCount(1, $result->records);
        $this->assertSame('order-search-1', $result->records[0]['external_id']);

        Http::assertSent(function ($request) {
            if (! str_starts_with($request->url(), 'https://shop.example.com/api/search/order')) {
                return false;
            }

            return $request->method() === 'POST'
                && ($request->data()['limit'] ?? null) === 50
                && ($request->data()['page'] ?? null) === 1
                && ($request->data()['filter'] ?? null) === [];
        });

        Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://shop.example.com/api/search/order')
            && $request->method() === 'GET');
    }

    public function test_admin_can_open_ai_connector_builder_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.dashboards.connections.ai-create', $dashboard));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Admin/Dashboards/Connections/AiCreate')
            ->has('dashboard'));
    }

    /**
     * @return array{0: ClientDashboard, 1: ConnectorBlueprint, 2: Connection}
     */
    protected function createHubspotLikeSetup(): array
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-hubspot'])->id,
            'name' => 'HubSpot Dashboard',
            'slug' => 'hubspot-dashboard',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $dashboard->company_id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'hubspot',
            'label' => 'HubSpot',
            'status' => ConnectorBlueprintStatus::Ready,
            'original_prompt' => 'Connect HubSpot deals and contacts',
            'auth_config' => [
                'type' => 'bearer',
                'credential_key' => 'access_token',
            ],
            'credential_schema' => [
                ['key' => 'access_token', 'label' => 'Private app access token', 'type' => 'password'],
            ],
            'sync_config' => [
                'base_url' => 'https://api.hubapi.com',
                'test_endpoint' => '/crm/v3/objects/deals?limit=1',
            ],
            'transform_config' => [
                'hubspot_deal' => [
                    'metrics' => [
                        ['key' => 'deal_amount', 'value_path' => 'amount'],
                        ['key' => 'deals', 'value' => 1],
                    ],
                ],
                'hubspot_contact' => [
                    'metrics' => [
                        ['key' => 'contacts', 'value' => 1],
                    ],
                ],
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'deals',
            'resource_type' => 'hubspot_deal',
            'path_template' => '/crm/v3/objects/deals',
            'query_params' => ['limit' => 100],
            'pagination' => ['type' => 'none'],
            'response_mapping' => [
                'records_path' => 'results',
                'id_path' => 'id',
                'date_path' => 'properties.createdate',
                'fields' => [
                    ['source' => 'properties.amount', 'target' => 'amount'],
                    ['source' => 'properties.dealstage', 'target' => 'dealstage'],
                    ['source' => 'properties.createdate', 'target' => 'date'],
                ],
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'contacts',
            'resource_type' => 'hubspot_contact',
            'path_template' => '/crm/v3/objects/contacts',
            'query_params' => ['limit' => 100],
            'pagination' => ['type' => 'none'],
            'response_mapping' => [
                'records_path' => 'results',
                'id_path' => 'id',
                'date_path' => 'properties.createdate',
                'fields' => [
                    ['source' => 'properties.email', 'target' => 'email'],
                    ['source' => 'properties.createdate', 'target' => 'date'],
                ],
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'HubSpot',
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'encrypted_credentials' => [
                'access_token' => 'pat-test-token',
            ],
        ]);

        return [$dashboard, $blueprint->fresh(['streams']), $connection];
    }
}
