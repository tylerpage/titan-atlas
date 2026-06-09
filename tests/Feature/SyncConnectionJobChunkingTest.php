<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\SyncRunType;
use App\Enums\SyncStatus;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Services\Ingestion\SyncConnectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncConnectionJobChunkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.google.client_id' => 'test-client-id',
            'titan.google.client_secret' => 'test-client-secret',
            'titan.search_console.chunk_days' => 1,
            'titan.search_console.row_limit' => 1,
            'titan.search_console.data_lag_days' => 3,
            'titan.search_console.backfill_months' => 1,
        ]);

        Carbon::setTestNow('2025-06-10');
    }

    public function test_sync_dispatches_continuation_when_pages_per_job_limit_reached(): void
    {
        Queue::fake();

        config(['titan.sync.pages_per_job' => 1, 'titan.sync.max_seconds_per_job' => 300]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
            '*/searchAnalytics/query' => Http::sequence()
                ->push([
                    'rows' => [
                        ['keys' => ['2025-06-01'], 'clicks' => 1, 'impressions' => 10, 'ctr' => 0.1, 'position' => 4],
                    ],
                ])
                ->push([
                    'rows' => [
                        ['keys' => ['2025-06-02'], 'clicks' => 2, 'impressions' => 20, 'ctr' => 0.1, 'position' => 4],
                    ],
                ]),
        ]);

        $connection = $this->makeConnection();
        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => SyncRunType::Backfill,
            'status' => SyncStatus::Running,
        ]);

        $result = app(SyncConnectionService::class)->sync(
            $connection,
            SyncRunType::Backfill,
            $syncRun->id,
        );

        $this->assertSame(SyncStatus::Running, $result->status);
        $this->assertSame(1, $result->records_fetched);

        Queue::assertPushed(SyncConnectionJob::class, function (SyncConnectionJob $job) use ($syncRun) {
            return $job->syncRunId === $syncRun->id
                && $job->fetched === 1
                && $job->cursor !== null;
        });
    }

    protected function makeConnection(): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'GSC',
            'connector_type' => ConnectorType::SearchConsole,
            'encrypted_credentials' => [
                'site_url' => 'https://example.com/',
                'refresh_token' => 'refresh-token',
            ],
        ]);
    }
}
