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
            'https://advertising-api.amazon.com/*' => function ($request) use (&$pollCounts) {
                if (str_contains($request->url(), '/v2/profiles')) {
                    return Http::response([[
                        'profileId' => '1234567890',
                        'accountInfo' => ['name' => 'Acme Amazon'],
                        'currencyCode' => 'USD',
                    ]], 200);
                }

                if ($request->method() === 'POST' && str_contains($request->url(), '/reporting/reports')) {
                    return Http::response(['reportId' => 'amazon-report'], 200);
                }

                if ($request->method() === 'GET' && str_contains($request->url(), '/reporting/reports/amazon-report')) {
                    return Http::response([
                        'status' => 'COMPLETED',
                        'url' => 'https://reports.test/amazon.json',
                    ], 200);
                }

                return Http::response([], 404);
            },
            'https://reports.test/*' => Http::response([[
                'date' => '20260601',
                'cost' => 41.67,
                'impressions' => 1000,
                'clicks' => 50,
                'purchases14d' => 3,
                'sales14d' => 150,
                'campaignId' => 'cmp-1',
                'campaignName' => 'Sponsored Products',
                'searchTerm' => 'running shoes',
                'advertisedAsin' => 'B012345678',
                'advertisedSku' => 'SKU-1',
            ]], 200),
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
        $this->assertContains('ad_type_daily', array_column($records, 'resource_type'));
        $this->persistAndTransform($connection, $records);

        $data = app(AmazonAdsDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2026-06-01', 'end' => '2026-06-01'],
        );

        $this->assertSame('amazon_ads', $data['kind']);
        $this->assertEqualsWithDelta(125.0, $data['summary']['cost'], 0.05);
        $this->assertNotNull($data['summary']['roas']);
    }

    public function test_walmart_connect_connector_validates_and_normalizes_reports(): void
    {
        Http::fake([
            'https://developer.api.walmart.com/*' => function ($request) {
                if ($request->method() === 'POST' && str_contains($request->url(), '/snapshot')) {
                    return Http::response(['snapshotId' => 'wm-snapshot'], 200);
                }

                if ($request->method() === 'GET' && str_contains($request->url(), '/snapshot/')) {
                    return Http::response([
                        'status' => 'DONE',
                        'downloadUrl' => 'https://reports.test/walmart.json',
                    ], 200);
                }

                if ($request->method() === 'GET' && str_ends_with($request->url(), '/advertisers')) {
                    return Http::response([
                        'advertisers' => [[
                            'advertiserId' => 'wm-123',
                            'name' => 'Acme Walmart',
                            'currency' => 'USD',
                        ]],
                    ], 200);
                }

                return Http::response([], 404);
            },
            'https://reports.test/walmart.json' => Http::response([[
                'date' => '2026-06-01',
                'adSpend' => 80,
                'impressions' => 500,
                'clicks' => 25,
                'attributedOrders' => 2,
                'attributedSales' => 240,
                'campaignId' => 'wm-cmp-1',
                'campaignName' => 'Search',
                'keyword' => 'shoes',
                'pageType' => 'search',
                'tactic' => 'manual',
            ]], 200),
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
        $this->persistAndTransform($connection, $records);

        $data = app(WalmartConnectDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2026-06-01', 'end' => '2026-06-01'],
        );

        $this->assertSame('walmart_connect', $data['kind']);
        $this->assertSame(80.0, $data['summary']['cost']);
    }

    public function test_ebay_ads_connector_validates_and_normalizes_reports(): void
    {
        $pollCounts = [];

        Http::fake([
            'https://api.ebay.com/*' => function ($request) use (&$pollCounts) {
                if (str_contains($request->url(), '/ad_account') && $request->method() === 'GET') {
                    return Http::response([
                        'accounts' => [[
                            'accountId' => 'ebay-123',
                            'name' => 'Acme eBay',
                            'currency' => 'USD',
                        ]],
                    ], 200);
                }

                if ($request->method() === 'POST' && str_contains($request->url(), '/ad_report')) {
                    return Http::response(['reportId' => 'ebay-report'], 200);
                }

                if ($request->method() === 'GET' && str_contains($request->url(), '/ad_report/')) {
                    return Http::response([
                        'reportTaskStatus' => 'SUCCESS',
                        'reportHref' => 'https://reports.test/ebay.json',
                    ], 200);
                }

                return Http::response([], 404);
            },
            'https://reports.test/ebay.json' => Http::response([[
                'date' => '2026-06-01',
                'AD_FEES' => 55,
                'impressions' => 300,
                'clicks' => 15,
                'SALES' => 1,
                'SALE_AMOUNT' => 120,
                'campaignId' => 'ebay-cmp-1',
                'campaignName' => 'Promoted Listings',
                'listingId' => 'listing-1',
                'listingTitle' => 'Vintage Sneakers',
                'keyword' => 'sneakers',
            ]], 200),
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
            'titan.reports.poll_max_attempts' => 3,
            'titan.reports.poll_sleep_ms' => 0,
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
        } while ($result->hasMore && $cursor !== null && $iterations < 50);

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
