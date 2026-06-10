<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Jobs\Ingestion\TransformConnectionDataJob;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SyncRun;
use App\Services\Analytics\TransformConnectionDataService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class TransformConnectionJobChunkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.transform.payloads_per_chunk' => 1,
            'titan.transform.chunks_per_job' => 1,
            'titan.transform.max_seconds_per_job' => 300,
        ]);
    }

    public function test_transform_stops_early_when_chunk_limits_are_reached(): void
    {
        [$syncRun] = $this->makeSyncRunWithPayloads(3);

        $result = app(TransformConnectionDataService::class)->transform(
            $syncRun->fresh(['connection.clientDashboard']),
            purgeExisting: true,
        );

        $this->assertTrue($result->hasMore);
        $this->assertNotNull($result->lastPayloadId);
        $this->assertSame(4, $result->written);
    }

    public function test_transform_job_self_chains_until_all_payloads_are_processed(): void
    {
        Queue::fake();

        [$syncRun] = $this->makeSyncRunWithPayloads(3);

        (new TransformConnectionDataJob($syncRun->fresh(), purgeExisting: true))->handle(app(TransformConnectionDataService::class));

        Queue::assertPushed(TransformConnectionDataJob::class, 1);
    }

    public function test_chained_transform_jobs_write_all_metric_snapshots(): void
    {
        [$syncRun] = $this->makeSyncRunWithPayloads(3);

        $transformer = app(TransformConnectionDataService::class);
        $result = $transformer->transform($syncRun->fresh(['connection.clientDashboard']), purgeExisting: true);

        while ($result->hasMore && $result->lastPayloadId !== null) {
            $result = $transformer->transform(
                $syncRun->fresh(['connection.clientDashboard']),
                $result->lastPayloadId,
                purgeExisting: false,
            );
        }

        $this->assertFalse($result->hasMore);
        $this->assertDatabaseCount('metric_snapshots', 12);
    }

    /**
     * @return array{0: SyncRun, 1: Connection}
     */
    protected function makeSyncRunWithPayloads(int $count): array
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

        for ($i = 1; $i <= $count; $i++) {
            RawConnectorPayload::query()->create([
                'connection_id' => $connection->id,
                'sync_run_id' => $syncRun->id,
                'resource_type' => 'search_daily',
                'external_id' => "2025-06-0{$i}",
                'payload' => [
                    'date' => "2025-06-0{$i}",
                    'clicks' => $i,
                    'impressions' => $i * 10,
                    'ctr' => 0.1,
                    'position' => 4,
                ],
                'payload_hash' => hash('sha256', "daily-{$i}"),
                'fetched_at' => now(),
            ]);
        }

        return [$syncRun, $connection];
    }
}
