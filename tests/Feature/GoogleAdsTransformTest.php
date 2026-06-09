<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAdsTransformTest extends TestCase
{
    use RefreshDatabase;

    public function test_transform_emits_ads_metrics_from_spend_and_campaign_payloads(): void
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Google Ads',
            'connector_type' => ConnectorType::GoogleAds,
            'encrypted_credentials' => [
                'customer_id' => '1234567890',
                'refresh_token' => 'token',
            ],
        ]);

        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => 'running',
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'spend_daily',
            'external_id' => '2025-06-01',
            'payload' => [
                'date' => '2025-06-01',
                'cost' => 150.25,
                'impressions' => 1000,
                'clicks' => 50,
                'ctr' => 0.05,
                'conversions_value' => 500,
            ],
            'payload_hash' => hash('sha256', 'spend'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'campaign_daily',
            'external_id' => '2025-06-01:999',
            'payload' => [
                'date' => '2025-06-01',
                'campaign_id' => '999',
                'campaign_name' => 'Brand',
                'cost' => 75,
                'impressions' => 400,
                'clicks' => 20,
                'ctr' => 0.05,
                'conversions_value' => 200,
            ],
            'payload_hash' => hash('sha256', 'campaign'),
            'fetched_at' => now(),
        ]);

        app(TransformConnectionDataService::class)->transform($syncRun->fresh(['connection.clientDashboard']));

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'ad_spend',
            'metric_value' => 150.25,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'ads_impressions',
            'metric_value' => 1000,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'ads_clicks',
            'metric_value' => 50,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'ads_conversions_value',
            'metric_value' => 500,
        ]);
    }
}
