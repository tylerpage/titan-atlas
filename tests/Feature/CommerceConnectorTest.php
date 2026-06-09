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
use App\Services\Analytics\CommerceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CommerceConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopify_credentials_survive_encryption_roundtrip(): void
    {
        $connection = $this->createShopifyConnection();
        $connection->update([
            'encrypted_credentials' => [
                'shop_domain' => 'demo.myshopify.com',
                'access_token' => 'shpat_abc123xyz',
            ],
        ]);

        $connection->refresh();

        $this->assertSame('shpat_abc123xyz', $connection->credentials()['access_token']);
        $this->assertNotSame('shpat_abc123xyz', $connection->getAttributes()['encrypted_credentials']);
    }

    public function test_shopify_connector_validates_credentials_against_api(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'shop' => ['name' => 'Demo Store'],
            ], 200),
        ]);

        $connection = $this->createShopifyConnection();
        $connector = app(\App\Ingestion\Connectors\ShopifyConnector::class);

        $result = $connector->validateCredentials($connection);

        $this->assertTrue($result->valid);
        $this->assertSame('Connected to Demo Store', $result->message);
    }

    public function test_bigcommerce_connector_validates_credentials_against_api(): void
    {
        Http::fake([
            'api.bigcommerce.com/*' => Http::response([
                'name' => 'Acme Store',
            ], 200),
        ]);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'BigCommerce Store',
            'connector_type' => ConnectorType::BigCommerce,
            'encrypted_credentials' => [
                'store_hash' => 'abc123',
                'access_token' => 'demo-token',
            ],
        ]);

        $connector = app(\App\Ingestion\Connectors\BigCommerceConnector::class);
        $result = $connector->validateCredentials($connection);

        $this->assertTrue($result->valid);
        $this->assertSame('Connected to Acme Store', $result->message);
    }

    public function test_shopify_connector_fetches_and_normalizes_orders(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'orders' => [
                    [
                        'id' => 1001,
                        'name' => '#1001',
                        'created_at' => '2024-06-01T12:00:00-04:00',
                        'total_price' => '149.99',
                        'currency' => 'USD',
                        'source_name' => 'web',
                        'referring_site' => 'https://google.com',
                        'landing_site' => 'https://demo.myshopify.com/?utm_source=google&utm_medium=cpc',
                        'line_items' => [
                            [
                                'id' => 9001,
                                'product_id' => 501,
                                'variant_id' => 601,
                                'sku' => 'TEE-BLK-M',
                                'name' => 'Classic Tee',
                                'variant_title' => 'Black / M',
                                'quantity' => 2,
                                'price' => '49.99',
                                'total_discount' => '5.00',
                                'vendor' => 'Acme Apparel',
                                'image_url' => 'https://cdn.shopify.com/tee.jpg',
                            ],
                        ],
                    ],
                ],
            ], 200, [
                'Link' => '<https://demo.myshopify.com/admin/api/2024-10/orders.json?page_info=abc>; rel="next"',
            ]),
        ]);

        $connection = $this->createShopifyConnection();

        $connector = app(\App\Ingestion\Connectors\ShopifyConnector::class);
        $result = $connector->fetch($connection);

        $this->assertCount(2, $result->records);
        $this->assertTrue($result->hasMore);
        $this->assertSame('abc', $result->nextCursor);
        $this->assertSame('order', $result->records[0]['resource_type']);
        $this->assertSame('1001', $result->records[0]['external_id']);
        $this->assertSame('2024-06-01', $result->records[0]['payload']['date']);
        $this->assertSame(149.99, $result->records[0]['payload']['total']);
        $this->assertSame('google / cpc', $result->records[0]['payload']['source_medium']);
        $this->assertSame('web', $result->records[0]['payload']['channel']);

        $lineItem = $result->records[1];
        $this->assertSame('order_line_item', $lineItem['resource_type']);
        $this->assertSame('1001:9001', $lineItem['external_id']);
        $this->assertSame('TEE-BLK-M', $lineItem['payload']['sku']);
        $this->assertSame('Classic Tee', $lineItem['payload']['name']);
        $this->assertSame(2, $lineItem['payload']['quantity']);
        $this->assertSame(49.99, $lineItem['payload']['unit_price']);
        $this->assertSame(94.98, $lineItem['payload']['line_total']);
        $this->assertSame('https://cdn.shopify.com/tee.jpg', $lineItem['payload']['image_url']);
        $this->assertSame('google / cpc', $lineItem['payload']['source_medium']);
    }

    public function test_bigcommerce_connector_paginates_on_order_count_not_line_items(): void
    {
        config(['titan.commerce.orders_page_size' => 2]);

        Http::fake([
            'api.bigcommerce.com/stores/abc123/v2/orders/1001/products' => Http::response([
                ['id' => 11, 'sku' => 'A', 'name' => 'Item A', 'quantity' => 1, 'base_price' => '10.00', 'total_inc_tax' => '10.00'],
            ], 200),
            'api.bigcommerce.com/stores/abc123/v2/orders/1002/products' => Http::response([
                ['id' => 12, 'sku' => 'B', 'name' => 'Item B', 'quantity' => 1, 'base_price' => '20.00', 'total_inc_tax' => '20.00'],
            ], 200),
            'api.bigcommerce.com/stores/abc123/v2/orders/1003/products' => Http::response([], 200),
            'api.bigcommerce.com/stores/abc123/v2/orders?*page=1*' => Http::response([
                [
                    'id' => 1001,
                    'date_created' => '2024-06-01T10:00:00+00:00',
                    'total_inc_tax' => '10.00',
                    'currency_code' => 'USD',
                ],
                [
                    'id' => 1002,
                    'date_created' => '2024-06-02T10:00:00+00:00',
                    'total_inc_tax' => '20.00',
                    'currency_code' => 'USD',
                ],
            ], 200),
            'api.bigcommerce.com/stores/abc123/v2/orders?*page=2*' => Http::response([
                [
                    'id' => 1003,
                    'date_created' => '2024-06-03T10:00:00+00:00',
                    'total_inc_tax' => '30.00',
                    'currency_code' => 'USD',
                ],
            ], 200),
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => ClientDashboard::query()->create([
                'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
                'name' => 'Main',
                'slug' => 'main',
            ])->id,
            'name' => 'BigCommerce Store',
            'connector_type' => ConnectorType::BigCommerce,
            'encrypted_credentials' => [
                'store_hash' => 'abc123',
                'access_token' => 'demo-token',
            ],
        ]);

        $connector = app(\App\Ingestion\Connectors\BigCommerceConnector::class);

        $pageOne = $connector->fetch($connection);
        $this->assertTrue($pageOne->hasMore);
        $this->assertSame('2', $pageOne->nextCursor);
        $this->assertCount(4, $pageOne->records);

        $pageTwo = $connector->fetch($connection, $pageOne->nextCursor);
        $this->assertFalse($pageTwo->hasMore);
        $this->assertNull($pageTwo->nextCursor);
        $this->assertCount(1, $pageTwo->records);
    }

    public function test_bigcommerce_connector_fetches_order_line_items(): void
    {
        Http::fake([
            'api.bigcommerce.com/stores/abc123/v2/orders/2001/products' => Http::response([
                [
                    'id' => 77,
                    'product_id' => 88,
                    'variant_id' => 99,
                    'sku' => 'HAT-RED',
                    'name' => 'Red Hat',
                    'quantity' => 3,
                    'base_price' => '20.00',
                    'total_inc_tax' => '57.00',
                    'image_url' => 'https://cdn.bigcommerce.com/hat.jpg',
                ],
            ], 200),
            'api.bigcommerce.com/stores/abc123/v2/orders?*' => Http::response([
                [
                    'id' => 2001,
                    'date_created' => '2024-06-02T10:00:00+00:00',
                    'total_inc_tax' => '120.00',
                    'currency_code' => 'USD',
                    'order_source' => 'www',
                ],
            ], 200),
        ]);

        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'BigCommerce Store',
            'connector_type' => ConnectorType::BigCommerce,
            'encrypted_credentials' => [
                'store_hash' => 'abc123',
                'access_token' => 'demo-token',
            ],
        ]);

        $result = app(\App\Ingestion\Connectors\BigCommerceConnector::class)->fetch($connection);

        $this->assertCount(2, $result->records);
        $this->assertSame('order_line_item', $result->records[1]['resource_type']);
        $this->assertSame('2001:77', $result->records[1]['external_id']);
        $this->assertSame('HAT-RED', $result->records[1]['payload']['sku']);
        $this->assertSame(3, $result->records[1]['payload']['quantity']);
        $this->assertSame(57.0, $result->records[1]['payload']['line_total']);
    }

    public function test_commerce_dashboard_returns_top_products(): void
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

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order_line_item',
            'external_id' => '1001:1',
            'payload' => [
                'date' => '2024-06-01',
                'sku' => 'TEE-1',
                'name' => 'Classic Tee',
                'quantity' => 2,
                'line_total' => 80,
                'image_url' => 'https://cdn.shopify.com/tee.jpg',
            ],
            'payload_hash' => hash('sha256', 'line-1'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order_line_item',
            'external_id' => '1001:2',
            'payload' => [
                'date' => '2024-06-01',
                'sku' => 'HAT-1',
                'name' => 'Red Hat',
                'quantity' => 1,
                'line_total' => 30,
            ],
            'payload_hash' => hash('sha256', 'line-2'),
            'fetched_at' => now(),
        ]);

        $data = app(CommerceDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2024-06-01', 'end' => '2024-06-01'],
        );

        $this->assertCount(2, $data['top_products']);
        $this->assertSame('Classic Tee', $data['top_products'][0]['name']);
        $this->assertSame(80.0, $data['top_products'][0]['revenue']);
        $this->assertSame(2.0, $data['top_products'][0]['units_sold']);
    }

    public function test_commerce_dashboard_service_calculates_average_order_value(): void
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

        foreach ([
            ['external_id' => '1001', 'total' => 100],
            ['external_id' => '1002', 'total' => 300],
        ] as $order) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'resource_type' => 'order',
                'external_id' => $order['external_id'],
                'payload' => [
                    'date' => '2024-06-01',
                    'total' => $order['total'],
                ],
                'payload_hash' => hash('sha256', $order['external_id']),
                'fetched_at' => now(),
            ]);
        }

        $data = app(CommerceDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2024-06-01', 'end' => '2024-06-01'],
        );

        $this->assertSame(400.0, $data['summary']['revenue']);
        $this->assertSame(2.0, $data['summary']['orders']);
        $this->assertSame(200.0, $data['summary']['avg_order_value']);
    }

    public function test_commerce_dashboard_service_returns_revenue_series_and_orders(): void
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

        MetricSnapshot::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'snapshot_date' => '2024-06-01',
            'metric_key' => 'revenue',
            'metric_value' => 500,
            'currency' => 'USD',
            'dimensions' => ['connection_id' => $connection->id],
            'dimension_hash' => \App\Support\MetricDimensions::hash(['connection_id' => $connection->id]),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1001',
            'payload' => [
                'date' => '2024-06-01',
                'total' => 500,
                'order_number' => '#1001',
                'source_medium' => 'google / cpc',
                'channel' => 'web',
            ],
            'payload_hash' => hash('sha256', '1001'),
            'fetched_at' => now(),
        ]);

        $data = app(CommerceDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2024-06-01', 'end' => '2024-06-01'],
        );

        $this->assertSame(500.0, $data['summary']['revenue']);
        $this->assertSame(1.0, $data['summary']['orders']);
        $this->assertSame(500.0, $data['summary']['avg_order_value']);
        $this->assertCount(1, $data['revenue_series']);
        $this->assertSame('2024-06-01', $data['revenue_series'][0]['date']);
        $this->assertCount(1, $data['orders']);
        $this->assertSame('#1001', $data['orders'][0]['order_number']);
    }

    public function test_client_dashboard_shows_connector_tabs(): void
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify Store',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
        ]);

        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $this->actingAs($client)
            ->get(route('client.dashboard.show', ['dashboard' => $dashboard, 'connection' => $connection->id]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->has('connections', 1)
                ->where('selectedConnectionId', $connection->id)
                ->has('connectorData.summary')
                ->has('connectorData.revenue_series')
            );
    }

    protected function createShopifyConnection(): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
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
