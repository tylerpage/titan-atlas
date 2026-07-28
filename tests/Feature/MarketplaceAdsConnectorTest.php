<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\AmazonAdsConnector;
use App\Ingestion\Connectors\EbayAdsConnector;
use App\Ingestion\Connectors\WalmartConnectConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\AmazonAdsDashboardService;
use App\Services\Analytics\EbayAdsDashboardService;
use App\Services\Analytics\TransformConnectionDataService;
use App\Services\Analytics\WalmartConnectDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MarketplaceAdsConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_amazon_ads_connector_validates_and_normalizes_reports(): void
    {
        Http::fake([
            'https://advertising-api.amazon.com/*' => function ($request) {
                if (str_contains($request->url(), '/profiles')) {
                    return Http::response([
                        'profiles' => [[
                            'profileId' => '1234567890',
                            'accountInfo' => ['name' => 'Acme Amazon'],
                            'currencyCode' => 'USD',
                        ]],
                    ], 200);
                }

                return Http::response([
                    'rows' => [[
                        'date' => '2026-06-01',
                        'cost' => 125,
                        'impressions' => 1000,
                        'clicks' => 50,
                        'purchases' => 3,
                        'sales' => 450,
                        'campaign_id' => 'cmp-1',
                        'campaign_name' => 'Sponsored Products',
                        'campaign_type' => 'SP',
                    ]],
                ], 200);
            },
        ]);

        [$dashboard, $connection] = $this->makeConnection(
            ConnectorType::AmazonAds,
            ['access_token' => 'amazon-token', 'profile_id' => '1234567890'],
        );

        $this->configureSyncWindow('amazon_ads');
        $connector = app(AmazonAdsConnector::class);

        $validation = $connector->validateCredentials($connection);
        $this->assertTrue($validation->valid);
        $this->assertCount(1, $validation->debug['profiles'] ?? []);

        $records = $this->fetchAllRecords($connector, $connection);
        $this->assertGreaterThanOrEqual(2, count($records));
        $this->persistAndTransform($connection, $records);

        $data = app(AmazonAdsDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2026-06-01', 'end' => '2026-06-01'],
        );

        $this->assertSame('amazon_ads', $data['kind']);
        $this->assertSame(125.0, $data['summary']['cost']);
    }

    public function test_walmart_connect_connector_validates_and_normalizes_reports(): void
    {
        Http::fake([
            'https://developer.api.walmart.com/*' => function ($request) {
                if (str_contains($request->url(), '/advertisers') && $request->method() === 'GET') {
                    return Http::response([
                        'advertisers' => [[
                            'advertiserId' => 'wm-123',
                            'name' => 'Acme Walmart',
                            'currency' => 'USD',
                        ]],
                    ], 200);
                }

                return Http::response([
                    'rows' => [[
                        'date' => '2026-06-01',
                        'adSpend' => 80,
                        'impressions' => 500,
                        'clicks' => 25,
                        'attributedOrders' => 2,
                        'attributedSales' => 240,
                        'campaign_id' => 'wm-cmp-1',
                        'campaign_name' => 'Search',
                    ]],
                ], 200);
            },
        ]);

        [$dashboard, $connection] = $this->makeConnection(
            ConnectorType::WalmartConnect,
            ['access_token' => 'walmart-token', 'advertiser_id' => 'wm-123'],
        );

        $this->configureSyncWindow('walmart_connect');
        $connector = app(WalmartConnectConnector::class);

        $validation = $connector->validateCredentials($connection);
        $this->assertTrue($validation->valid);

        $records = $this->fetchAllRecords($connector, $connection);
        $this->assertNotEmpty($records);

        $data = app(WalmartConnectDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2026-06-01', 'end' => '2026-06-01'],
        );

        $this->assertSame('walmart_connect', $data['kind']);
    }

    public function test_ebay_ads_connector_validates_and_normalizes_reports(): void
    {
        Http::fake([
            'https://api.ebay.com/*' => function ($request) {
                if (str_contains($request->url(), '/ad_account') && $request->method() === 'GET') {
                    return Http::response([
                        'accounts' => [[
                            'accountId' => 'ebay-123',
                            'name' => 'Acme eBay',
                            'currency' => 'USD',
                        ]],
                    ], 200);
                }

                return Http::response([
                    'records' => [[
                        'date' => '2026-06-01',
                        'cost' => 55,
                        'impressions' => 300,
                        'clicks' => 15,
                        'conversions' => 1,
                        'conversions_value' => 120,
                        'campaign_id' => 'ebay-cmp-1',
                        'campaign_name' => 'Promoted Listings',
                    ]],
                ], 200);
            },
        ]);

        [$dashboard, $connection] = $this->makeConnection(
            ConnectorType::EbayAds,
            ['access_token' => 'ebay-token', 'account_id' => 'ebay-123'],
        );

        $this->configureSyncWindow('ebay_ads');
        $connector = app(EbayAdsConnector::class);

        $validation = $connector->validateCredentials($connection);
        $this->assertTrue($validation->valid);

        $records = $this->fetchAllRecords($connector, $connection);
        $this->assertNotEmpty($records);

        $this->persistAndTransform($connection, $records);

        $data = app(EbayAdsDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2026-06-01', 'end' => '2026-06-01'],
        );

        $this->assertSame('ebay_ads', $data['kind']);
        $this->assertSame(55.0, $data['summary']['cost']);
    }

    /**
     * @param  array<string, string>  $credentials
     * @return array{0: ClientDashboard, 1: Connection}
     */
    protected function makeConnection(ConnectorType $type, array $credentials): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-'.$type->value]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-'.$type->value,
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => $type->label(),
            'connector_type' => $type,
            'encrypted_credentials' => $credentials,
            'backfill_completed_at' => now(),
            'last_synced_at' => now(),
        ]);

        return [$dashboard, $connection];
    }

    protected function configureSyncWindow(string $configKey): void
    {
        config([
            "titan.{$configKey}.incremental_days" => 1,
            "titan.{$configKey}.chunk_days" => 7,
            "titan.{$configKey}.data_lag_days" => 1,
        ]);
    }

    /**
     * @return list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>
     */
    protected function fetchAllRecords(object $connector, Connection $connection): array
    {
        $allRecords = [];
        $cursor = null;
        $iterations = 0;

        do {
            $result = $connector->fetch($connection, $cursor);
            $allRecords = array_merge($allRecords, $result->records);
            $cursor = $result->nextCursor;
            $iterations++;
        } while ($result->hasMore && $cursor !== null && $iterations < 20);

        return $allRecords;
    }

    /**
     * @param  list<array{resource_type: string, external_id: string, payload: array<string, mixed>}>  $records
     */
    protected function persistAndTransform(Connection $connection, array $records): void
    {
        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'backfill',
            'status' => 'running',
        ]);

        foreach ($records as $record) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'sync_run_id' => $syncRun->id,
                'resource_type' => $record['resource_type'],
                'external_id' => $record['external_id'],
                'payload' => $record['payload'],
                'payload_hash' => hash('sha256', json_encode($record['payload'])),
                'fetched_at' => now(),
            ]);
        }

        app(TransformConnectionDataService::class)->transform($syncRun);
    }
}
