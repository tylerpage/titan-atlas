<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\RedditAdsConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\RedditAdsDashboardService;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RedditAdsConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_connector_validates_fetches_and_transforms_report_rows(): void
    {
        Http::fake([
            'https://ads-api.reddit.com/api/v3/accounts/t2_test/campaigns*' => Http::response([
                'data' => [['id' => 'camp_1', 'name' => 'Brand']],
            ], 200),
            'https://ads-api.reddit.com/api/v3/accounts/t2_test/reports' => Http::sequence()
                ->push([
                    'data' => [[
                        'date' => '2026-06-01',
                        'impressions' => 1000,
                        'clicks' => 50,
                        'spend_micro' => 125000000,
                        'ctr' => 0.05,
                        'conversions' => 3,
                    ]],
                ], 200)
                ->push([
                    'data' => [[
                        'date' => '2026-06-01',
                        'campaign_id' => 'camp_1',
                        'campaign_name' => 'Brand',
                        'impressions' => 1000,
                        'clicks' => 50,
                        'spend_micro' => 125000000,
                        'ctr' => 0.05,
                        'conversions' => 3,
                    ]],
                ], 200),
        ]);

        [$dashboard, $connection] = $this->makeConnection();
        $connection->update([
            'backfill_completed_at' => now(),
            'last_synced_at' => now(),
        ]);

        config([
            'titan.reddit_ads.incremental_days' => 1,
            'titan.reddit_ads.chunk_days' => 7,
            'titan.reddit_ads.data_lag_days' => 1,
        ]);

        $connector = app(RedditAdsConnector::class);

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
        $this->assertSame(125.0, $allRecords[0]['payload']['cost']);

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
                'payload_hash' => hash('sha256', json_encode($record['payload'])),
                'fetched_at' => now(),
            ]);
        }

        $written = app(TransformConnectionDataService::class)->transform($syncRun)->written;

        $this->assertGreaterThan(0, $written);
    }

    public function test_dashboard_service_returns_reddit_ads_summary(): void
    {
        [$dashboard, $connection] = $this->makeConnection();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'spend_daily',
            'external_id' => '2026-06-01',
            'payload' => [
                'date' => '2026-06-01',
                'cost' => 125,
                'impressions' => 1000,
                'clicks' => 50,
                'ctr' => 0.05,
                'conversions_value' => 0,
            ],
            'payload_hash' => hash('sha256', 'reddit-spend'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'campaign_daily',
            'external_id' => '2026-06-01:camp_1',
            'payload' => [
                'date' => '2026-06-01',
                'campaign_id' => 'camp_1',
                'campaign_name' => 'Brand',
                'cost' => 125,
                'impressions' => 1000,
                'clicks' => 50,
                'ctr' => 0.05,
                'conversions_value' => 0,
            ],
            'payload_hash' => hash('sha256', 'reddit-campaign'),
            'fetched_at' => now(),
        ]);

        $data = app(RedditAdsDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2026-06-01', 'end' => '2026-06-01'],
        );

        $this->assertSame('reddit_ads', $data['kind']);
        $this->assertSame(125.0, $data['summary']['cost']);
        $this->assertCount(1, $data['campaigns']);
        $this->assertSame('Brand', $data['campaigns'][0]['campaign_name']);
    }

    /**
     * @return array{0: ClientDashboard, 1: Connection}
     */
    protected function makeConnection(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-reddit']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-reddit',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Reddit Ads',
            'connector_type' => ConnectorType::RedditAds,
            'encrypted_credentials' => [
                'access_token' => 'reddit-token',
                'account_id' => 't2_test',
            ],
        ]);

        return [$dashboard, $connection];
    }
}
