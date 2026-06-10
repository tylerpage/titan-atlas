<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SearchConsoleTransformTest extends TestCase
{
    use RefreshDatabase;

    public function test_transform_emits_search_metrics_from_daily_payloads_and_skips_keywords(): void
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

        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => 'running',
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'keyword',
            'external_id' => '2025-06-01:hash',
            'payload' => [
                'date' => '2025-06-01',
                'keyword' => 'organic cotton',
                'clicks' => 12,
                'impressions' => 240,
                'ctr' => 0.05,
                'position' => 3.2,
            ],
            'payload_hash' => hash('sha256', 'keyword'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'search_daily',
            'external_id' => '2025-06-01',
            'payload' => [
                'date' => '2025-06-01',
                'clicks' => 50,
                'impressions' => 1000,
                'ctr' => 0.05,
                'position' => 8.1,
            ],
            'payload_hash' => hash('sha256', 'daily'),
            'fetched_at' => now(),
        ]);

        app(TransformConnectionDataService::class)->transform(
            $syncRun->fresh(['connection.clientDashboard']),
            purgeExisting: true,
        );

        $this->assertDatabaseMissing('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'keyword_rank',
        ]);

        $dailyClicks = MetricSnapshot::query()
            ->where('metric_key', 'search_clicks')
            ->where('metric_value', 50)
            ->first();

        $this->assertNotNull($dailyClicks);
        $this->assertSame($connection->id, $dailyClicks->dimensions['connection_id']);
    }
}
