<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\Shopify\ShopifyAnalyticsClient;
use App\Ingestion\Connectors\Shopify\ShopifyHttpClient;
use App\Ingestion\Connectors\ShopifyConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Services\Analytics\CommerceDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopify_analytics_client_parses_session_rows(): void
    {
        Http::fake([
            'demo.myshopify.com/admin/api/2025-10/graphql.json' => Http::response([
                'data' => [
                    'shopifyqlQuery' => [
                        'tableData' => [
                            'columns' => [
                                ['name' => 'utm_source'],
                                ['name' => 'utm_medium'],
                                ['name' => 'day'],
                                ['name' => 'sessions'],
                                ['name' => 'online_store_visitors'],
                            ],
                            'rows' => [
                                [
                                    'utm_source' => 'google',
                                    'utm_medium' => 'cpc',
                                    'day' => '2024-06-01',
                                    'sessions' => '120',
                                    'online_store_visitors' => '95',
                                ],
                            ],
                        ],
                        'parseErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $records = (new ShopifyAnalyticsClient('demo.myshopify.com', new ShopifyHttpClient('token')))->sessionsBySourceMedium('2024-06-01', '2024-06-01');

        $this->assertCount(1, $records);
        $this->assertSame('session_attribution', $records[0]['resource_type']);
        $this->assertSame('google', $records[0]['payload']['source']);
        $this->assertSame('cpc', $records[0]['payload']['medium']);
        $this->assertSame('google / cpc', $records[0]['payload']['source_medium']);
        $this->assertSame(120.0, $records[0]['payload']['sessions']);
        $this->assertSame(95.0, $records[0]['payload']['visitors']);
    }

    public function test_shopify_connector_fetches_analytics_after_orders(): void
    {
        Http::fake([
            'demo.myshopify.com/admin/api/2024-10/orders.json*' => Http::response([
                'orders' => [],
            ], 200),
            'demo.myshopify.com/admin/api/2025-10/graphql.json' => Http::response([
                'data' => [
                    'shopifyqlQuery' => [
                        'tableData' => [
                            'columns' => [],
                            'rows' => [
                                [
                                    'utm_source' => 'facebook',
                                    'utm_medium' => 'paid',
                                    'day' => now()->toDateString(),
                                    'sessions' => '40',
                                    'online_store_visitors' => '30',
                                ],
                            ],
                        ],
                        'parseErrors' => [],
                    ],
                ],
            ], 200),
        ]);

        $connection = $this->createShopifyConnection();
        $connector = app(ShopifyConnector::class);
        $result = $connector->fetch($connection);

        $this->assertTrue($result->hasMore);
        $this->assertStringStartsWith('analytics:', (string) $result->nextCursor);

        $analyticsResult = $connector->fetch($connection, $result->nextCursor);

        $this->assertCount(1, $analyticsResult->records);
        $this->assertSame('session_attribution', $analyticsResult->records[0]['resource_type']);
    }

    public function test_commerce_dashboard_aggregates_sessions_by_source_medium(): void
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
            ['source' => 'google', 'medium' => 'cpc', 'sessions' => 100, 'visitors' => 80],
            ['source' => 'google', 'medium' => 'cpc', 'sessions' => 20, 'visitors' => 15],
            ['source' => 'email', 'medium' => 'newsletter', 'sessions' => 50, 'visitors' => 40],
        ] as $index => $row) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'resource_type' => 'session_attribution',
                'external_id' => "session-{$index}",
                'payload' => [
                    'date' => '2024-06-01',
                    'source' => $row['source'],
                    'medium' => $row['medium'],
                    'source_medium' => "{$row['source']} / {$row['medium']}",
                    'sessions' => $row['sessions'],
                    'visitors' => $row['visitors'],
                ],
                'payload_hash' => hash('sha256', "session-{$index}"),
                'fetched_at' => now(),
            ]);
        }

        $data = app(CommerceDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2024-06-01', 'end' => '2024-06-01'],
        );

        $this->assertSame(170.0, $data['summary']['sessions']);
        $this->assertSame(135.0, $data['summary']['visitors']);
        $this->assertCount(2, $data['sessions_by_source_medium']);
        $this->assertSame('google / cpc', $data['sessions_by_source_medium'][0]['source_medium']);
        $this->assertSame(120.0, $data['sessions_by_source_medium'][0]['sessions']);
    }

    protected function createShopifyConnection(): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()])->id,
            'name' => 'Main',
            'slug' => 'main-'.uniqid(),
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
