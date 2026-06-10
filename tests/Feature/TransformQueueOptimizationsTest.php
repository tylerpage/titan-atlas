<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\SyncRunType;
use App\Enums\WidgetType;
use App\Jobs\Ingestion\TransformConnectionDataJob;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\MetricSnapshot;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use App\Services\Analytics\WidgetDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransformQueueOptimizationsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.transform.payloads_per_chunk' => 100,
            'titan.transform.chunks_per_job' => 10,
            'titan.transform.max_seconds_per_job' => 300,
        ]);
    }

    public function test_finalize_transform_does_not_purge_existing_metrics(): void
    {
        [$syncRun, $connection, $dashboard] = $this->createSearchConsoleFixtures();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'search_daily',
            'external_id' => '2025-06-01',
            'payload' => ['date' => '2025-06-01', 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 4],
            'payload_hash' => hash('sha256', 'daily-1'),
            'fetched_at' => now(),
        ]);

        $transformer = app(TransformConnectionDataService::class);
        $transformer->transform($syncRun->fresh(['connection.clientDashboard']), purgeExisting: true);

        $this->assertDatabaseCount('metric_snapshots', 4);

        $connection->update(['last_transformed_payload_id' => 1]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'search_daily',
            'external_id' => '2025-06-02',
            'payload' => ['date' => '2025-06-02', 'clicks' => 3, 'impressions' => 30, 'ctr' => 0.1, 'position' => 5],
            'payload_hash' => hash('sha256', 'daily-2'),
            'fetched_at' => now(),
        ]);

        $transformer->transform(
            $syncRun->fresh(['connection.clientDashboard']),
            afterPayloadId: 1,
            syncRunCatchUp: true,
        );

        $this->assertGreaterThan(4, MetricSnapshot::query()->count());
    }

    public function test_sync_run_catch_up_reprocesses_updated_payload(): void
    {
        [$syncRun, $connection] = $this->createSearchConsoleFixtures();

        $payload = RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'search_daily',
            'external_id' => '2025-06-01',
            'payload' => ['date' => '2025-06-01', 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 4],
            'payload_hash' => hash('sha256', 'daily-old'),
            'fetched_at' => now(),
        ]);

        app(TransformConnectionDataService::class)->transform(
            $syncRun->fresh(['connection.clientDashboard']),
            purgeExisting: true,
        );

        $connection->update(['last_transformed_payload_id' => $payload->id]);

        $catchUpRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => 'incremental',
            'status' => 'success',
        ]);

        $payload->update([
            'sync_run_id' => $catchUpRun->id,
            'payload' => ['date' => '2025-06-01', 'clicks' => 99, 'impressions' => 500, 'ctr' => 0.2, 'position' => 2],
            'payload_hash' => hash('sha256', 'daily-new'),
        ]);

        app(TransformConnectionDataService::class)->transform(
            $catchUpRun->fresh(['connection.clientDashboard']),
            afterPayloadId: $payload->id,
            syncRunCatchUp: true,
        );

        $clicks = MetricSnapshot::query()
            ->where('metric_key', 'search_clicks')
            ->whereDate('snapshot_date', '2025-06-01')
            ->value('metric_value');

        $this->assertSame(99.0, (float) $clicks);
    }

    public function test_resource_allowlist_skips_keyword_payloads(): void
    {
        [$syncRun, $connection] = $this->createSearchConsoleFixtures();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'sync_run_id' => $syncRun->id,
            'resource_type' => 'keyword',
            'external_id' => '2025-06-01:abc',
            'payload' => ['date' => '2025-06-01', 'keyword' => 'running shoes', 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 3],
            'payload_hash' => hash('sha256', 'keyword-1'),
            'fetched_at' => now(),
        ]);

        app(TransformConnectionDataService::class)->transform(
            $syncRun->fresh(['connection.clientDashboard']),
            purgeExisting: true,
        );

        $this->assertDatabaseMissing('metric_snapshots', [
            'metric_key' => 'keyword_rank',
        ]);
    }

    public function test_transform_job_is_unique_per_connection(): void
    {
        [$syncRun] = $this->createSearchConsoleFixtures();

        $job = new TransformConnectionDataJob($syncRun);

        $this->assertSame('transform-connection-'.$syncRun->connection_id, $job->uniqueId());
        $this->assertInstanceOf(\Illuminate\Contracts\Queue\ShouldBeUnique::class, $job);
    }

    public function test_top_keywords_widget_reads_from_raw_payloads(): void
    {
        [$syncRun, $connection, $dashboard] = $this->createSearchConsoleFixtures();

        foreach ([
            ['keyword' => 'alpha shoes', 'position' => 2, 'clicks' => 40],
            ['keyword' => 'beta boots', 'position' => 5, 'clicks' => 10],
            ['keyword' => 'gamma sandals', 'position' => 1, 'clicks' => 25],
        ] as $index => $row) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'sync_run_id' => $syncRun->id,
                'resource_type' => 'keyword',
                'external_id' => "2025-06-01:{$index}",
                'payload' => [
                    'date' => '2025-06-01',
                    'keyword' => $row['keyword'],
                    'clicks' => $row['clicks'],
                    'impressions' => 100,
                    'ctr' => 0.1,
                    'position' => $row['position'],
                ],
                'payload_hash' => hash('sha256', "kw-{$index}"),
                'fetched_at' => now(),
            ]);
        }

        $data = app(WidgetDataService::class)->dataFor(
            $dashboard,
            WidgetType::TopKeywords,
            'custom',
            ['start' => '2025-06-01', 'end' => '2025-06-01'],
        );

        $this->assertCount(3, $data['items']);
        $this->assertSame('gamma sandals', $data['items'][0]['keyword']);
        $this->assertSame(1.0, $data['items'][0]['position']);
        $this->assertSame(25.0, $data['items'][0]['clicks']);
    }

    public function test_backfill_skips_mid_sync_transform_by_default(): void
    {
        Queue::fake();

        [$syncRun, $connection] = $this->createSearchConsoleFixtures();

        $connection->update(['backfill_started_at' => now(), 'backfill_completed_at' => null]);
        $syncRun->update(['type' => SyncRunType::Backfill]);

        $service = app(\App\Services\Ingestion\SyncConnectionService::class);

        $reflection = new \ReflectionClass($service);
        $method = $reflection->getMethod('dispatchIncrementalTransform');
        $method->invoke($service, $connection->fresh(), $syncRun->fresh());

        Queue::assertNothingPushed();
    }

    /**
     * @return array{0: SyncRun, 1: Connection, 2: ClientDashboard}
     */
    protected function createSearchConsoleFixtures(): array
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
            'status' => 'success',
        ]);

        return [$syncRun, $connection, $dashboard];
    }
}
