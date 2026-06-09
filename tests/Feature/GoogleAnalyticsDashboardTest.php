<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Services\Analytics\GoogleAnalyticsDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoogleAnalyticsDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_service_merges_ga4_and_gsc_data(): void
    {
        [$dashboard, $ga4, $gsc] = $this->makeConnections();

        RawConnectorPayload::query()->create([
            'connection_id' => $ga4->id,
            'resource_type' => 'traffic_daily',
            'external_id' => '2025-06-01',
            'payload' => ['date' => '2025-06-01', 'visitors' => 1000, 'active_users' => 600, 'sessions' => 800],
            'payload_hash' => hash('sha256', 'ga4-traffic'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $ga4->id,
            'resource_type' => 'events_daily',
            'external_id' => '2025-06-01:'.hash('sha256', 'purchase'),
            'payload' => ['date' => '2025-06-01', 'event_name' => 'purchase', 'event_count' => 25],
            'payload_hash' => hash('sha256', 'ga4-event'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $gsc->id,
            'resource_type' => 'search_daily',
            'external_id' => '2025-06-01',
            'payload' => ['date' => '2025-06-01', 'clicks' => 50, 'impressions' => 1000, 'ctr' => 0.05, 'position' => 4],
            'payload_hash' => hash('sha256', 'gsc-daily'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $gsc->id,
            'resource_type' => 'keyword',
            'external_id' => '2025-06-01:'.hash('sha256', 'organic cotton'),
            'payload' => [
                'date' => '2025-06-01',
                'keyword' => 'organic cotton',
                'clicks' => 12,
                'impressions' => 240,
                'ctr' => 0.05,
                'position' => 6.5,
            ],
            'payload_hash' => hash('sha256', 'gsc-keyword'),
            'fetched_at' => now(),
        ]);

        $data = app(GoogleAnalyticsDashboardService::class)->dataFor(
            $dashboard,
            $ga4,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
        );

        $this->assertSame('google_analytics', $data['kind']);
        $this->assertFalse($data['gsc_required']);
        $this->assertSame(1000.0, $data['summary']['visitors']);
        $this->assertSame(600.0, $data['summary']['active_users']);
        $this->assertSame(800.0, $data['summary']['sessions']);
        $this->assertSame(1000.0, $data['summary']['impressions']);
        $this->assertSame(50.0, $data['summary']['url_clicks']);
        $this->assertCount(1, $data['events']);
        $this->assertSame('purchase', $data['events'][0]['event_name']);
        $this->assertCount(1, $data['top_queries']);
        $this->assertCount(1, $data['top_keywords']);
    }

    public function test_dashboard_service_flags_missing_gsc_connection(): void
    {
        [$dashboard, $ga4] = $this->makeConnections(includeGsc: false);

        $data = app(GoogleAnalyticsDashboardService::class)->dataFor(
            $dashboard,
            $ga4,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
        );

        $this->assertTrue($data['gsc_required']);
        $this->assertNull($data['gsc_connection']);
        $this->assertSame([], $data['top_queries']);
    }

    /**
     * @return array{0: ClientDashboard, 1: Connection, 2?: Connection}
     */
    protected function makeConnections(bool $includeGsc = true): array
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $ga4 = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'GA4',
            'connector_type' => ConnectorType::GoogleAnalytics,
            'encrypted_credentials' => ['property_id' => '123456789', 'refresh_token' => 'token'],
        ]);

        if (! $includeGsc) {
            return [$dashboard, $ga4];
        }

        $gsc = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'GSC',
            'connector_type' => ConnectorType::SearchConsole,
            'encrypted_credentials' => ['site_url' => 'https://example.com/', 'refresh_token' => 'token'],
        ]);

        return [$dashboard, $ga4, $gsc];
    }
}
