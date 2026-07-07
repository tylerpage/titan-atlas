<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\MetaAdsConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\MetaAdsDashboardService;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetaAdsConnectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_connector_validates_lists_accounts_and_normalizes_insights(): void
    {
        Http::fake([
            'https://graph.facebook.com/*' => function ($request) {
                $url = $request->url();

                if (str_contains($url, '/me/adaccounts')) {
                    return Http::response([
                        'data' => [[
                            'id' => 'act_123456789',
                            'name' => 'Acme Ads',
                            'currency' => 'USD',
                        ]],
                    ], 200);
                }

                if (str_contains($url, '/act_123456789?')) {
                    return Http::response([
                        'id' => 'act_123456789',
                        'name' => 'Acme Ads',
                        'currency' => 'USD',
                    ], 200);
                }

                if (str_contains($url, '/insights')) {
                    $level = $request->data()['level'] ?? 'account';
                    $breakdowns = $request->data()['breakdowns'] ?? null;

                    if ($breakdowns === 'publisher_platform,platform_position') {
                        return Http::response([
                            'data' => [[
                                'date_start' => '2026-06-01',
                                'spend' => '40.00',
                                'impressions' => '400',
                                'inline_link_clicks' => '20',
                                'publisher_platform' => 'facebook',
                                'platform_position' => 'feed',
                                'actions' => [['action_type' => 'purchase', 'value' => '1']],
                                'action_values' => [['action_type' => 'purchase', 'value' => '120.00']],
                            ]],
                        ], 200);
                    }

                    if ($breakdowns === 'device_platform') {
                        return Http::response([
                            'data' => [[
                                'date_start' => '2026-06-01',
                                'spend' => '40.00',
                                'impressions' => '400',
                                'inline_link_clicks' => '20',
                                'device_platform' => 'mobile',
                                'actions' => [['action_type' => 'purchase', 'value' => '1']],
                                'action_values' => [['action_type' => 'purchase', 'value' => '120.00']],
                            ]],
                        ], 200);
                    }

                    if ($level === 'campaign') {
                        return Http::response([
                            'data' => [[
                                'date_start' => '2026-06-01',
                                'campaign_id' => '2380000001',
                                'campaign_name' => 'Prospecting',
                                'objective' => 'OUTCOME_SALES',
                                'spend' => '125.00',
                                'impressions' => '1000',
                                'inline_link_clicks' => '50',
                                'reach' => '800',
                                'actions' => [['action_type' => 'purchase', 'value' => '3']],
                                'action_values' => [['action_type' => 'purchase', 'value' => '450.00']],
                            ]],
                        ], 200);
                    }

                    return Http::response([
                        'data' => [[
                            'date_start' => '2026-06-01',
                            'spend' => '125.00',
                            'impressions' => '1000',
                            'inline_link_clicks' => '50',
                            'reach' => '800',
                            'actions' => [['action_type' => 'purchase', 'value' => '3']],
                            'action_values' => [['action_type' => 'purchase', 'value' => '450.00']],
                        ]],
                    ], 200);
                }

                return Http::response(['data' => []], 200);
            },
        ]);

        [$dashboard, $connection] = $this->makeConnection();
        $connection->update([
            'backfill_completed_at' => now(),
            'last_synced_at' => now(),
        ]);

        config([
            'titan.meta_ads.incremental_days' => 1,
            'titan.meta_ads.chunk_days' => 7,
            'titan.meta_ads.data_lag_days' => 1,
        ]);

        $connector = app(MetaAdsConnector::class);

        $validation = $connector->validateCredentials($connection);
        $this->assertTrue($validation->valid);
        $this->assertCount(1, $validation->debug['ad_accounts'] ?? []);

        $allRecords = [];
        $cursor = null;
        $iterations = 0;

        do {
            $result = $connector->fetch($connection, $cursor);
            $allRecords = array_merge($allRecords, $result->records);
            $cursor = $result->nextCursor;
            $iterations++;
        } while ($result->hasMore && $cursor !== null && $iterations < 20);

        $this->assertGreaterThanOrEqual(4, count($allRecords));

        $spendDaily = collect($allRecords)->firstWhere('resource_type', 'spend_daily');
        $this->assertNotNull($spendDaily);
        $this->assertSame(125.0, $spendDaily['payload']['cost']);
        $this->assertSame(3.0, $spendDaily['payload']['conversions']);
        $this->assertSame(450.0, $spendDaily['payload']['conversions_value']);

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

    public function test_dashboard_service_returns_meta_ads_summary(): void
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
                'reach' => 800,
                'conversions' => 3,
                'conversions_value' => 450,
            ],
            'payload_hash' => hash('sha256', 'meta-spend'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'campaign_daily',
            'external_id' => '2026-06-01:2380000001',
            'payload' => [
                'date' => '2026-06-01',
                'campaign_id' => '2380000001',
                'campaign_name' => 'Prospecting',
                'objective' => 'OUTCOME_SALES',
                'cost' => 125,
                'impressions' => 1000,
                'clicks' => 50,
                'reach' => 800,
                'conversions' => 3,
                'conversions_value' => 450,
            ],
            'payload_hash' => hash('sha256', 'meta-campaign'),
            'fetched_at' => now(),
        ]);

        $data = app(MetaAdsDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2026-06-01', 'end' => '2026-06-01'],
        );

        $this->assertSame('meta_ads', $data['kind']);
        $this->assertSame(125.0, $data['summary']['cost']);
        $this->assertSame(450.0, $data['summary']['conversions_value']);
        $this->assertSame(3.6, $data['summary']['roas']);
        $this->assertCount(1, $data['campaigns']);
        $this->assertSame('Prospecting', $data['campaigns'][0]['campaign_name']);
        $this->assertCount(1, $data['top_campaigns']);
    }

    /**
     * @return array{0: ClientDashboard, 1: Connection}
     */
    protected function makeConnection(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-meta']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-meta',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Meta Ads',
            'connector_type' => ConnectorType::MetaAds,
            'encrypted_credentials' => [
                'access_token' => 'meta-token',
                'ad_account_id' => 'act_123456789',
            ],
        ]);

        return [$dashboard, $connection];
    }
}
