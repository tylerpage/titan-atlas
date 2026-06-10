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

class GoogleAnalyticsTransformTest extends TestCase
{
    use RefreshDatabase;

    public function test_transform_emits_ga4_metrics_from_traffic_and_event_payloads(): void
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'GA4',
            'connector_type' => ConnectorType::GoogleAnalytics,
            'encrypted_credentials' => [
                'property_id' => '123456789',
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
            'resource_type' => 'traffic_daily',
            'external_id' => '2025-06-01',
            'payload' => [
                'date' => '2025-06-01',
                'visitors' => 500,
                'active_users' => 300,
                'sessions' => 420,
            ],
            'payload_hash' => hash('sha256', 'traffic'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'events_daily',
            'external_id' => '2025-06-01:'.hash('sha256', 'purchase'),
            'payload' => [
                'date' => '2025-06-01',
                'event_name' => 'purchase',
                'event_count' => 12,
            ],
            'payload_hash' => hash('sha256', 'event'),
            'fetched_at' => now(),
        ]);

        app(TransformConnectionDataService::class)->transform(
            $syncRun->fresh(['connection.clientDashboard']),
            purgeExisting: true,
        );

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'visitors',
            'metric_value' => 500,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'active_users',
            'metric_value' => 300,
        ]);

        $this->assertDatabaseHas('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'sessions',
            'metric_value' => 420,
        ]);

        $this->assertDatabaseMissing('metric_snapshots', [
            'client_dashboard_id' => $dashboard->id,
            'metric_key' => 'event_count',
        ]);
    }
}
