<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Services\Analytics\GoogleAdsDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAdsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_service_returns_summary_campaigns_and_prior_year_spend(): void
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
                'ctr' => 0.05,
                'conversions_value' => 250,
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
                'ctr' => 0.05,
                'conversions_value' => 200,
            ],
            'payload_hash' => hash('sha256', 'spend-prior'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'campaign_daily',
            'external_id' => '2025-06-01:111',
            'payload' => [
                'date' => '2025-06-01',
                'campaign_id' => '111',
                'campaign_name' => 'Brand',
                'cost' => 100,
                'impressions' => 1000,
                'clicks' => 50,
                'ctr' => 0.05,
                'conversions_value' => 250,
            ],
            'payload_hash' => hash('sha256', 'campaign'),
            'fetched_at' => now(),
        ]);

        $data = app(GoogleAdsDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
        );

        $this->assertSame('google_ads', $data['kind']);
        $this->assertSame(100.0, $data['summary']['cost']);
        $this->assertSame(1000.0, $data['summary']['impressions']);
        $this->assertSame(50.0, $data['summary']['clicks']);
        $this->assertSame(5.0, $data['summary']['ctr']);
        $this->assertSame(250.0, $data['summary']['conversions_value']);
        $this->assertCount(1, $data['spend_series']);
        $this->assertSame('2025-06-01', $data['spend_series'][0]['date']);
        $this->assertSame(100.0, $data['spend_series'][0]['value']);
        $this->assertCount(1, $data['prior_year_spend_series']);
        $this->assertSame('2025-06-01', $data['prior_year_spend_series'][0]['date']);
        $this->assertSame(80.0, $data['prior_year_spend_series'][0]['value']);
        $this->assertCount(1, $data['campaigns']);
        $this->assertSame('Brand', $data['campaigns'][0]['campaign_name']);
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
            'name' => 'Google Ads',
            'connector_type' => ConnectorType::GoogleAds,
            'encrypted_credentials' => [
                'customer_id' => '1234567890',
                'refresh_token' => 'token',
            ],
        ]);

        return [$dashboard, $connection];
    }
}
