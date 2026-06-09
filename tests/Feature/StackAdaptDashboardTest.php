<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Services\Analytics\StackAdaptDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StackAdaptDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_service_returns_summary_channels_campaigns_and_insights(): void
    {
        [$dashboard, $connection] = $this->makeConnection();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'spend_daily',
            'external_id' => '2025-06-01',
            'payload' => [
                'date' => '2025-06-01',
                'cost' => 100,
                'impressions' => 1000,
                'clicks' => 50,
                'ctr' => 5,
                'conversions' => 10,
                'conversions_value' => 250,
                'secondary_conversions' => 3,
                'roas' => 2.5,
            ],
            'payload_hash' => hash('sha256', 'spend-current'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'spend_daily',
            'external_id' => '2024-06-01',
            'payload' => [
                'date' => '2024-06-01',
                'cost' => 80,
                'impressions' => 800,
                'clicks' => 40,
                'ctr' => 5,
                'conversions' => 8,
                'conversions_value' => 200,
                'secondary_conversions' => 2,
                'roas' => 2.5,
            ],
            'payload_hash' => hash('sha256', 'spend-prior'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'channel_daily',
            'external_id' => '2025-06-01:CTV',
            'payload' => [
                'date' => '2025-06-01',
                'channel_type' => 'CTV',
                'cost' => 60,
                'impressions' => 600,
                'clicks' => 30,
                'conversions' => 6,
                'conversions_value' => 150,
                'video_starts' => 500,
                'audio_starts' => 0,
            ],
            'payload_hash' => hash('sha256', 'channel'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'campaign_daily',
            'external_id' => '2025-06-01:111',
            'payload' => [
                'date' => '2025-06-01',
                'campaign_id' => '111',
                'campaign_name' => 'Brand CTV',
                'campaign_group_name' => 'Brand',
                'channel_type' => 'CTV',
                'cost' => 100,
                'impressions' => 1000,
                'clicks' => 50,
                'ctr' => 5,
                'conversions' => 10,
                'conversions_value' => 250,
            ],
            'payload_hash' => hash('sha256', 'campaign'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'insight_geo_daily',
            'external_id' => '2025-06-01:US',
            'payload' => [
                'date' => '2025-06-01',
                'dimension_key' => 'US',
                'dimension_label' => 'United States',
                'cost' => 40,
                'impressions' => 400,
                'clicks' => 20,
                'conversions' => 4,
            ],
            'payload_hash' => hash('sha256', 'geo'),
            'fetched_at' => now(),
        ]);

        $data = app(StackAdaptDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
        );

        $this->assertSame('stackadapt', $data['kind']);
        $this->assertSame(100.0, $data['summary']['cost']);
        $this->assertSame(10.0, $data['summary']['conversions']);
        $this->assertSame(2.5, $data['summary']['roas']);
        $this->assertCount(1, $data['spend_series']);
        $this->assertSame(80.0, $data['prior_year_spend_series'][0]['value']);
        $this->assertSame('CTV', $data['channels'][0]['channel_type']);
        $this->assertTrue($data['video_audio']['show']);
        $this->assertSame('Brand CTV', $data['campaigns'][0]['campaign_name']);
        $this->assertSame('United States', $data['top_geos'][0]['dimension_label']);
    }

    /**
     * @return array{0: ClientDashboard, 1: Connection}
     */
    protected function makeConnection(): array
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

        return [$dashboard, $connection];
    }
}
