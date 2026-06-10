<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\SyncRunType;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Services\Ingestion\SyncConnectionService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class SyncConnectionStreamFanOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.google.client_id' => 'test-client-id',
            'titan.google.client_secret' => 'test-client-secret',
            'titan.sync.stream_fan_out_enabled' => true,
        ]);

        Carbon::setTestNow('2025-06-10');
    }

    public function test_new_sync_dispatches_one_job_per_stream_when_fan_out_enabled(): void
    {
        Queue::fake();

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
        ]);

        $connection = $this->makeConnection();

        $syncRun = app(SyncConnectionService::class)->sync($connection, SyncRunType::Backfill);

        Queue::assertPushed(SyncConnectionJob::class, 4);

        $streams = [];
        Queue::assertPushed(SyncConnectionJob::class, function (SyncConnectionJob $job) use (&$streams, $syncRun) {
            if ($job->syncRunId !== $syncRun->id || $job->cursor === null) {
                return false;
            }

            $decoded = json_decode(substr($job->cursor, 4), true);
            $streams[] = $decoded['stream'] ?? null;

            return ($decoded['fan_out'] ?? false) === true;
        });

        $this->assertEqualsCanonicalizing(
            ['search_daily', 'keyword', 'search_page', 'search_device'],
            array_filter($streams),
        );
    }

    public function test_fan_out_can_be_disabled_via_config(): void
    {
        Queue::fake();
        config(['titan.sync.stream_fan_out_enabled' => false]);

        Http::fake([
            'oauth2.googleapis.com/token' => Http::response(['access_token' => 'access-token'], 200),
            'www.googleapis.com/webmasters/v3/sites' => Http::response([
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ], 200),
            '*/searchAnalytics/query' => Http::response(['rows' => []], 200),
        ]);

        $connection = $this->makeConnection();

        app(SyncConnectionService::class)->sync($connection, SyncRunType::Backfill);

        Queue::assertNotPushed(SyncConnectionJob::class, function (SyncConnectionJob $job) {
            if ($job->cursor === null || ! str_starts_with($job->cursor, 'gsc:')) {
                return false;
            }

            $decoded = json_decode(substr($job->cursor, 4), true);

            return is_array($decoded) && ($decoded['fan_out'] ?? false) === true;
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
