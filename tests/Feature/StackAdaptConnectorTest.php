<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\StackAdaptConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class StackAdaptConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.stackadapt.chunk_days' => 1,
            'titan.stackadapt.data_lag_days' => 1,
            'titan.stackadapt.backfill_months' => 1,
            'titan.stackadapt.page_size' => 250,
        ]);

        Carbon::setTestNow('2025-06-10');
    }

    public function test_validate_credentials_lists_advertisers_without_selection(): void
    {
        $this->fakeStackAdaptGraphql([
            'advertisers' => [
                ['id' => 'adv-1', 'name' => 'Irish Titan'],
            ],
        ]);

        $connection = $this->makeConnection([
            'graphql_api_key' => 'graphql-key',
        ]);

        $result = app(StackAdaptConnector::class)->validateCredentials($connection);

        $this->assertTrue($result->valid);
        $this->assertStringContainsString('Select an advertiser', $result->message ?? '');
        $this->assertCount(1, $result->debug['advertisers'] ?? []);
    }

    public function test_validate_credentials_confirms_advertiser_access(): void
    {
        $this->fakeStackAdaptGraphql([
            'advertisers' => [
                ['id' => 'adv-1', 'name' => 'Irish Titan'],
            ],
            'advertiser_delivery' => [
                [
                    'granularity' => ['time' => '2025-06-03'],
                    'metrics' => [
                        'cost' => 10,
                        'impressionsBigint' => '100',
                        'clicksBigint' => '5',
                        'ctr' => 0.05,
                        'conversionsBigint' => '1',
                        'conversionRevenue' => 25,
                        'roas' => 2.5,
                    ],
                ],
            ],
        ]);

        $connection = $this->makeConnection([
            'graphql_api_key' => 'graphql-key',
            'advertiser_id' => 'adv-1',
        ]);

        $result = app(StackAdaptConnector::class)->validateCredentials($connection);

        $this->assertTrue($result->valid);
        $this->assertStringContainsString('Irish Titan', $result->message ?? '');
    }

    public function test_fetch_emits_spend_daily_records(): void
    {
        $this->fakeStackAdaptGraphql([
            'advertiser_delivery' => [
                [
                    'granularity' => ['time' => '2025-06-01'],
                    'metrics' => [
                        'cost' => 150.25,
                        'impressionsBigint' => '1000',
                        'clicksBigint' => '50',
                        'ctr' => 5,
                        'conversionsBigint' => '10',
                        'conversionRevenue' => 500,
                        'roas' => 3.3,
                    ],
                ],
            ],
        ]);

        $connection = $this->makeConnection([
            'graphql_api_key' => 'graphql-key',
            'advertiser_id' => 'adv-1',
        ]);

        $result = app(StackAdaptConnector::class)->fetch($connection, null);

        $this->assertCount(1, $result->records);
        $this->assertSame('spend_daily', $result->records[0]['resource_type']);
        $this->assertSame('2025-06-01', $result->records[0]['external_id']);
        $this->assertSame(150.25, $result->records[0]['payload']['cost']);
        $this->assertSame(1000.0, $result->records[0]['payload']['impressions']);
        $this->assertTrue($result->hasMore);
    }

    public function test_fetch_emits_campaign_and_channel_daily_records(): void
    {
        $this->fakeStackAdaptGraphql([
            'campaign_delivery' => [
                [
                    'granularity' => ['time' => '2025-06-01'],
                    'campaign' => [
                        'id' => 'cmp-1',
                        'name' => 'CTV Awareness',
                        'channelType' => 'CTV',
                        'campaignGroup' => ['id' => 'grp-1', 'name' => 'Brand'],
                    ],
                    'metrics' => [
                        'cost' => 100,
                        'impressionsBigint' => '500',
                        'clicksBigint' => '10',
                        'ctr' => 2,
                        'conversionsBigint' => '3',
                        'conversionRevenue' => 120,
                        'roas' => 1.2,
                    ],
                ],
                [
                    'granularity' => ['time' => '2025-06-01'],
                    'campaign' => [
                        'id' => 'cmp-2',
                        'name' => 'Display Retargeting',
                        'channelType' => 'DISPLAY',
                        'campaignGroup' => ['id' => 'grp-2', 'name' => 'Performance'],
                    ],
                    'metrics' => [
                        'cost' => 50,
                        'impressionsBigint' => '300',
                        'clicksBigint' => '8',
                        'ctr' => 2.67,
                        'conversionsBigint' => '2',
                        'conversionRevenue' => 80,
                        'roas' => 1.6,
                    ],
                ],
            ],
        ]);

        $connection = $this->makeConnection([
            'graphql_api_key' => 'graphql-key',
            'advertiser_id' => 'adv-1',
        ]);

        $campaignCursor = 'sa:'.json_encode([
            'stream' => 'campaign_daily',
            'start_date' => '2025-06-01',
            'end_date' => '2025-06-09',
        ]);

        $campaignResult = app(StackAdaptConnector::class)->fetch($connection, $campaignCursor);

        $this->assertCount(2, $campaignResult->records);
        $this->assertSame('campaign_daily', $campaignResult->records[0]['resource_type']);
        $this->assertSame('CTV', $campaignResult->records[0]['payload']['channel_type']);

        $channelCursor = 'sa:'.json_encode([
            'stream' => 'channel_daily',
            'start_date' => '2025-06-01',
            'end_date' => '2025-06-09',
        ]);

        $channelResult = app(StackAdaptConnector::class)->fetch($connection, $channelCursor);

        $this->assertCount(2, $channelResult->records);
        $this->assertSame('channel_daily', $channelResult->records[0]['resource_type']);
        $this->assertSame(100.0, collect($channelResult->records)->firstWhere('external_id', '2025-06-01:CTV')['payload']['cost']);
        $this->assertSame(50.0, collect($channelResult->records)->firstWhere('external_id', '2025-06-01:DISPLAY')['payload']['cost']);
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
            'name' => 'StackAdapt',
            'connector_type' => ConnectorType::StackAdapt,
            'encrypted_credentials' => $credentials,
        ]);
    }

    /**
     * @param  array{
     *     advertisers?: list<array{id: string, name: string}>,
     *     advertiser_delivery?: list<array<string, mixed>>,
     *     campaign_delivery?: list<array<string, mixed>>,
     *     campaign_insight?: list<array<string, mixed>>
     * }  $fixtures
     */
    protected function fakeStackAdaptGraphql(array $fixtures): void
    {
        Http::fake(function ($request) use ($fixtures) {
            $body = $request->data();
            $query = is_array($body) ? (string) ($body['query'] ?? '') : '';

            if (str_contains($query, 'StackAdaptAdvertisers')) {
                return Http::response([
                    'data' => [
                        'advertisers' => [
                            'nodes' => $fixtures['advertisers'] ?? [],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'StackAdaptAdvertiserDelivery')) {
                return Http::response([
                    'data' => [
                        'advertiserDelivery' => [
                            '__typename' => 'AdvertiserDeliveryOutcome',
                            'records' => [
                                'nodes' => $fixtures['advertiser_delivery'] ?? [],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'StackAdaptCampaignDelivery')) {
                return Http::response([
                    'data' => [
                        'campaignDelivery' => [
                            '__typename' => 'CampaignDeliveryOutcome',
                            'records' => [
                                'nodes' => $fixtures['campaign_delivery'] ?? [],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ],
                ]);
            }

            if (str_contains($query, 'StackAdaptCampaignInsight')) {
                return Http::response([
                    'data' => [
                        'campaignInsight' => [
                            '__typename' => 'CampaignInsightOutcome',
                            'records' => [
                                'nodes' => $fixtures['campaign_insight'] ?? [],
                                'pageInfo' => ['hasNextPage' => false, 'endCursor' => null],
                            ],
                        ],
                    ],
                ]);
            }

            return Http::response([
                'errors' => [['message' => 'Unexpected StackAdapt GraphQL query in test.']],
            ], 400);
        });
    }
}
