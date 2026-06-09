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

class StackAdaptTransformTest extends TestCase
{
    use RefreshDatabase;

    public function test_transform_emits_ads_metrics_from_stackadapt_payloads(): void
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'StackAdapt',
            'connector_type' => ConnectorType::StackAdapt,
            'encrypted_credentials' => [
                'graphql_api_key' => 'graphql-key',
                'advertiser_id' => 'adv-1',
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
                'ctr' => 5,
                'conversions' => 10,
                'conversions_value' => 500,
                'roas' => 3.3,
            ],
            'payload_hash' => hash('sha256', 'spend'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'channel_daily',
            'external_id' => '2025-06-01:CTV',
            'payload' => [
                'date' => '2025-06-01',
                'channel_type' => 'CTV',
                'cost' => 75,
                'impressions' => 400,
                'clicks' => 20,
                'ctr' => 5,
                'conversions' => 4,
                'conversions_value' => 200,
                'roas' => 2.67,
            ],
            'payload_hash' => hash('sha256', 'channel'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'insight_geo_daily',
            'external_id' => '2025-06-01:US',
            'payload' => [
                'date' => '2025-06-01',
                'dimension_key' => 'US',
                'dimension_label' => 'United States',
                'cost' => 40,
                'impressions' => 200,
                'clicks' => 10,
                'conversions' => 2,
            ],
            'payload_hash' => hash('sha256', 'geo'),
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
            'metric_key' => 'ads_conversions',
            'metric_value' => 10,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'ad_spend',
            'metric_value' => 75,
            'dimensions' => json_encode([
                'connection_id' => $connection->id,
                'channel_type' => 'CTV',
            ]),
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'ad_spend',
            'metric_value' => 40,
            'dimensions' => json_encode([
                'connection_id' => $connection->id,
                'insight_type' => 'geo',
                'dimension_key' => 'US',
                'dimension_label' => 'United States',
            ]),
        ]);
    }
}
