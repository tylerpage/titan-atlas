<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Services\Analytics\SearchConsoleDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchConsoleDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_service_aggregates_summary_and_series(): void
    {
        [$dashboard, $connection] = $this->makeDashboardAndConnection();

        foreach ([
            ['date' => '2025-06-01', 'clicks' => 50, 'impressions' => 1000],
            ['date' => '2025-06-02', 'clicks' => 30, 'impressions' => 600],
        ] as $row) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'resource_type' => 'search_daily',
                'external_id' => $row['date'],
                'payload' => [
                    'date' => $row['date'],
                    'clicks' => $row['clicks'],
                    'impressions' => $row['impressions'],
                    'ctr' => 0.05,
                    'position' => 4.0,
                ],
                'payload_hash' => hash('sha256', $row['date']),
                'fetched_at' => now(),
            ]);
        }

        $data = app(SearchConsoleDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-02'],
        );

        $this->assertSame('search_console', $data['kind']);
        $this->assertSame(3, $data['data_lag']['days']);
        $this->assertSame(now()->subDays(3)->toDateString(), $data['data_lag']['complete_through']);
        $this->assertSame(1600.0, $data['summary']['impressions']);
        $this->assertSame(80.0, $data['summary']['clicks']);
        $this->assertSame(5.0, $data['summary']['ctr']);
        $this->assertCount(2, $data['impressions_series']);
        $this->assertCount(2, $data['clicks_series']);
    }

    public function test_dashboard_service_returns_device_breakdown_and_top_queries(): void
    {
        [$dashboard, $connection] = $this->makeDashboardAndConnection();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'search_device',
            'external_id' => '2025-06-01:MOBILE',
            'payload' => [
                'date' => '2025-06-01',
                'device' => 'MOBILE',
                'clicks' => 40,
                'impressions' => 800,
                'ctr' => 0.05,
                'position' => 3.0,
            ],
            'payload_hash' => hash('sha256', 'device-mobile'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'search_device',
            'external_id' => '2025-06-01:DESKTOP',
            'payload' => [
                'date' => '2025-06-01',
                'device' => 'DESKTOP',
                'clicks' => 60,
                'impressions' => 1200,
                'ctr' => 0.05,
                'position' => 4.0,
            ],
            'payload_hash' => hash('sha256', 'device-desktop'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'keyword',
            'external_id' => '2025-06-01:'.hash('sha256', 'organic cotton'),
            'payload' => [
                'date' => '2025-06-01',
                'keyword' => 'organic cotton',
                'clicks' => 12,
                'impressions' => 240,
                'ctr' => 0.05,
                'position' => 3.2,
            ],
            'payload_hash' => hash('sha256', 'keyword-1'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'keyword',
            'external_id' => '2025-06-01:'.hash('sha256', 'cotton shirts'),
            'payload' => [
                'date' => '2025-06-01',
                'keyword' => 'cotton shirts',
                'clicks' => 8,
                'impressions' => 400,
                'ctr' => 0.02,
                'position' => 5.1,
            ],
            'payload_hash' => hash('sha256', 'keyword-2'),
            'fetched_at' => now(),
        ]);

        $data = app(SearchConsoleDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
        );

        $this->assertCount(2, $data['device_breakdown']);
        $this->assertSame('Desktop', $data['device_breakdown'][0]['device']);
        $this->assertSame(60.0, $data['device_breakdown'][0]['clicks']);
        $this->assertSame(60.0, $data['device_breakdown'][0]['share_percent']);

        $this->assertCount(2, $data['top_queries']);
        $this->assertSame('cotton shirts', $data['top_queries'][0]['query']);
        $this->assertSame(400.0, $data['top_queries'][0]['impressions']);
        $this->assertSame(8.0, $data['top_queries'][0]['clicks']);
    }

    public function test_dashboard_service_calculates_query_impression_change_percent(): void
    {
        [$dashboard, $connection] = $this->makeDashboardAndConnection();

        foreach ([
            ['date' => '2025-05-31', 'resource_type' => 'search_daily', 'external_id' => '2025-05-31', 'clicks' => 50, 'impressions' => 1000],
            ['date' => '2025-06-01', 'resource_type' => 'search_daily', 'external_id' => '2025-06-01', 'clicks' => 80, 'impressions' => 1500],
            ['date' => '2025-05-31', 'resource_type' => 'keyword', 'external_id' => '2025-05-31:'.hash('sha256', 'organic cotton'), 'keyword' => 'organic cotton', 'clicks' => 5, 'impressions' => 100],
            ['date' => '2025-06-01', 'resource_type' => 'keyword', 'external_id' => '2025-06-01:'.hash('sha256', 'organic cotton'), 'keyword' => 'organic cotton', 'clicks' => 8, 'impressions' => 150],
        ] as $row) {
            $payload = [
                'date' => $row['date'],
                'clicks' => $row['clicks'],
                'impressions' => $row['impressions'],
                'ctr' => 0.05,
                'position' => 3.0,
            ];

            if ($row['resource_type'] === 'keyword') {
                $payload['keyword'] = $row['keyword'];
            }

            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'resource_type' => $row['resource_type'],
                'external_id' => $row['external_id'],
                'payload' => $payload,
                'payload_hash' => hash('sha256', $row['external_id']),
                'fetched_at' => now(),
            ]);
        }

        $data = app(SearchConsoleDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
            'previous_period',
        );

        $this->assertSame(50.0, $data['summary']['impressions_change_percent']);
        $this->assertSame(60.0, $data['summary']['clicks_change_percent']);
        $this->assertSame(50.0, $data['top_queries'][0]['impressions_change_percent']);
    }

    /**
     * @return array{0: ClientDashboard, 1: Connection}
     */
    protected function makeDashboardAndConnection(): array
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'GSC',
            'connector_type' => ConnectorType::SearchConsole,
            'encrypted_credentials' => [
                'site_url' => 'https://example.com/',
                'refresh_token' => 'token',
            ],
        ]);

        return [$dashboard, $connection];
    }
}
