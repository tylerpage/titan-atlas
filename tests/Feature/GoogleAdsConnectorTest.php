<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\GoogleAds\GoogleAdsApiClient;
use App\Ingestion\Connectors\GoogleAdsConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAdsConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.google.client_id' => 'test-client-id',
            'titan.google.client_secret' => 'test-client-secret',
            'titan.google_ads.developer_token' => 'test-developer-token',
            'titan.google_ads.chunk_days' => 7,
            'titan.google_ads.data_lag_days' => 1,
            'titan.google_ads.backfill_months' => 1,
        ]);

        Carbon::setTestNow('2025-06-10');
    }

    public function test_list_selectable_customers_includes_mcc_client_accounts(): void
    {
        $this->fakeGoogleAdsApis([
            'accessible_customers' => ['1111111111'],
            'customer_names' => [
                '1111111111' => 'Irish Titan',
            ],
            'manager_clients' => [
                '1111111111' => [
                    [
                        'customerClient' => [
                            'clientCustomer' => 'customers/2222222222',
                            'descriptiveName' => 'Client Brand',
                            'hidden' => false,
                            'level' => 1,
                            'manager' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $customers = app(GoogleAdsApiClient::class)->listSelectableCustomers('refresh-token');

        $this->assertCount(2, $customers);

        $manager = collect($customers)->firstWhere('customerId', '1111111111');
        $client = collect($customers)->firstWhere('customerId', '2222222222');

        $this->assertNotNull($manager);
        $this->assertNull($manager['managerCustomerId']);
        $this->assertSame('Irish Titan (manager)', $manager['pickerLabel']);
        $this->assertSame('Client Brand', $client['displayName']);
        $this->assertSame('1111111111', $client['managerCustomerId']);
        $this->assertSame('Irish Titan > Client Brand', $client['pickerLabel']);
    }

    public function test_list_selectable_customers_includes_nested_mcc_clients(): void
    {
        $this->fakeGoogleAdsApis([
            'accessible_customers' => ['1111111111'],
            'customer_names' => [
                '1111111111' => 'Irish Titan',
                '3333333333' => 'Client Brand',
            ],
            'manager_clients' => [
                '1111111111' => [
                    [
                        'customerClient' => [
                            'clientCustomer' => 'customers/2222222222',
                            'descriptiveName' => 'Regional MCC',
                            'hidden' => false,
                            'level' => 1,
                            'manager' => true,
                        ],
                    ],
                ],
                '2222222222' => [
                    [
                        'customerClient' => [
                            'clientCustomer' => 'customers/3333333333',
                            'descriptiveName' => 'Client Brand',
                            'hidden' => false,
                            'level' => 1,
                            'manager' => false,
                        ],
                    ],
                ],
            ],
        ]);

        $customers = app(GoogleAdsApiClient::class)->listSelectableCustomers('refresh-token');

        $client = collect($customers)->firstWhere('customerId', '3333333333');

        $this->assertNotNull($client);
        $this->assertSame('2222222222', $client['managerCustomerId']);
        $this->assertSame('Irish Titan > Client Brand', $client['pickerLabel']);
    }

    public function test_validate_credentials_confirms_customer_access(): void
    {
        $this->fakeGoogleAdsApis();

        $connection = $this->makeConnection([
            'customer_id' => '1234567890',
            'refresh_token' => 'refresh-token',
        ]);

        $result = app(GoogleAdsConnector::class)->validateCredentials($connection);

        $this->assertTrue($result->valid);
        $this->assertStringContainsString('Google Ads account', $result->message ?? '');
    }

    public function test_fetch_paginates_spend_daily_rows(): void
    {
        $this->fakeGoogleAdsApis([
            'search_responses' => [
                [
                    [
                        'results' => [
                            [
                                'segments' => ['date' => '2025-06-01'],
                                'metrics' => [
                                    'costMicros' => '1500000',
                                    'impressions' => '1000',
                                    'clicks' => '50',
                                    'ctr' => 0.05,
                                    'conversionsValue' => 250.5,
                                ],
                            ],
                            [
                                'segments' => ['date' => '2025-06-02'],
                                'metrics' => [
                                    'costMicros' => '2000000',
                                    'impressions' => '1200',
                                    'clicks' => '60',
                                    'ctr' => 0.05,
                                    'conversionsValue' => 300,
                                ],
                            ],
                        ],
                    ],
                ],
                [
                    [
                        'results' => [
                            [
                                'segments' => ['date' => '2025-06-08'],
                                'metrics' => [
                                    'costMicros' => '500000',
                                    'impressions' => '400',
                                    'clicks' => '10',
                                    'ctr' => 0.025,
                                    'conversionsValue' => 50,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $connection = $this->makeConnection([
            'customer_id' => '1234567890',
            'refresh_token' => 'refresh-token',
        ]);

        $connector = app(GoogleAdsConnector::class);
        $first = $connector->fetch($connection, null);

        $this->assertCount(2, $first->records);
        $this->assertTrue($first->hasMore);
        $this->assertSame('spend_daily', $first->records[0]['resource_type']);
        $this->assertSame(1.5, $first->records[0]['payload']['cost']);
        $this->assertSame(1000.0, $first->records[0]['payload']['impressions']);

        $second = $connector->fetch($connection, $first->nextCursor);

        $this->assertCount(1, $second->records);
        $this->assertTrue($second->hasMore);
    }

    public function test_fetch_emits_campaign_daily_records(): void
    {
        $this->fakeGoogleAdsApis([
            'search_responses' => [
                [
                    [
                        'results' => [
                            [
                                'segments' => ['date' => '2025-06-01'],
                                'campaign' => ['id' => '999', 'name' => 'Brand Search'],
                                'metrics' => [
                                    'costMicros' => '3000000',
                                    'impressions' => '500',
                                    'clicks' => '25',
                                    'ctr' => 0.05,
                                    'conversionsValue' => 120,
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'start_stream' => 'campaign_daily',
        ]);

        $connection = $this->makeConnection([
            'customer_id' => '1234567890',
            'refresh_token' => 'refresh-token',
        ]);

        $cursor = 'gads:'.json_encode([
            'stream' => 'campaign_daily',
            'start_date' => '2025-06-01',
            'end_date' => '2025-06-09',
        ]);

        $result = app(GoogleAdsConnector::class)->fetch($connection, $cursor);

        $this->assertCount(1, $result->records);
        $this->assertSame('campaign_daily', $result->records[0]['resource_type']);
        $this->assertSame('999', $result->records[0]['payload']['campaign_id']);
        $this->assertSame('Brand Search', $result->records[0]['payload']['campaign_name']);
        $this->assertSame(3.0, $result->records[0]['payload']['cost']);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function makeConnection(array $credentials): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Google Ads',
            'connector_type' => ConnectorType::GoogleAds,
            'encrypted_credentials' => $credentials,
        ]);
    }

    /**
     * @param  array{
     *     search_responses?: list<list<array<string, mixed>>>,
     *     start_stream?: string,
     *     accessible_customers?: list<string>,
     *     customer_names?: array<string, string>,
     *     manager_clients?: array<string, list<array<string, mixed>>>
     * }  $options
     */
    protected function fakeGoogleAdsApis(array $options = []): void
    {
        $accessibleCustomers = $options['accessible_customers'] ?? ['1234567890'];
        $customerNames = $options['customer_names'] ?? ['1234567890' => 'Test Ads Account'];
        $managerClients = $options['manager_clients'] ?? [];
        $searchResponses = $options['search_responses'] ?? [
            [
                [
                    'results' => [
                        [
                            'segments' => ['date' => '2025-06-01'],
                            'metrics' => [
                                'costMicros' => '1000000',
                                'impressions' => '100',
                                'clicks' => '5',
                                'ctr' => 0.05,
                                'conversionsValue' => 10,
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $searchIndex = 0;

        Http::fake(function ($request) use (&$searchIndex, $searchResponses, $accessibleCustomers, $customerNames, $managerClients) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-token'], 200);
            }

            if (str_contains($request->url(), 'customers:listAccessibleCustomers')) {
                return Http::response([
                    'resourceNames' => array_map(
                        fn (string $customerId) => "customers/{$customerId}",
                        $accessibleCustomers,
                    ),
                ], 200);
            }

            if (str_contains($request->url(), 'googleAds:searchStream')) {
                $body = $request->data();
                $query = is_array($body) ? (string) ($body['query'] ?? '') : '';

                if (str_contains($query, 'customer_client')) {
                    if (preg_match('#/customers/(\d+)/googleAds:searchStream#', $request->url(), $matches) === 1) {
                        $managerId = $matches[1];
                        $clients = $managerClients[$managerId] ?? [];

                        return Http::response([['results' => $clients]], 200);
                    }

                    return Http::response([['results' => []]], 200);
                }

                if (str_contains($query, 'customer.descriptive_name')) {
                    if (preg_match('#/customers/(\d+)/googleAds:searchStream#', $request->url(), $matches) === 1) {
                        $customerId = $matches[1];
                        $name = $customerNames[$customerId] ?? 'Test Ads Account';

                        return Http::response([
                            [
                                'results' => [
                                    [
                                        'customer' => [
                                            'descriptiveName' => $name,
                                        ],
                                    ],
                                ],
                            ],
                        ], 200);
                    }
                }

                $body = $searchResponses[$searchIndex] ?? [[]];
                $searchIndex++;

                return Http::response($body, 200);
            }

            return Http::response([], 404);
        });
    }
}
