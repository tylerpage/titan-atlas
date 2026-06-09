<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\SyncRunType;
use App\Enums\SyncStatus;
use App\Ingestion\Connectors\Shopify\ShopifyHttpClient;
use App\Ingestion\Connectors\Shopify\ShopifyRateLimitException;
use App\Ingestion\Connectors\ShopifyConnector;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\SyncRun;
use App\Services\Ingestion\SyncConnectionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class ShopifyRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.shopify.rate_limit.max_retries' => 2,
            'titan.shopify.rate_limit.base_delay_ms' => 0,
            'titan.shopify.rate_limit.max_delay_ms' => 0,
            'titan.shopify.rate_limit.request_delay_ms' => 0,
            'titan.shopify.rate_limit.job_retry_delay_seconds' => 30,
        ]);

        Sleep::fake();
    }

    public function test_http_client_retries_after_rate_limit_response(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::sequence()
                ->push(['errors' => 'Rate limited. Please retry later.'], 429, ['Retry-After' => '2'])
                ->push(['orders' => []], 200),
        ]);

        $response = (new ShopifyHttpClient('token'))->get(
            'https://demo.myshopify.com/admin/api/2024-10/orders.json?limit=1',
        );

        $this->assertTrue($response->successful());
        Http::assertSentCount(2);
    }

    public function test_http_client_throws_after_exhausting_retries(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'errors' => 'Rate limited. Please retry later.',
            ], 429),
        ]);

        $this->expectException(ShopifyRateLimitException::class);

        (new ShopifyHttpClient('token'))->get(
            'https://demo.myshopify.com/admin/api/2024-10/orders.json?limit=1',
        );
    }

    public function test_sync_reschedules_job_instead_of_failing_on_rate_limit(): void
    {
        Queue::fake();

        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'errors' => 'Rate limited. Please retry later.',
            ], 429),
        ]);

        config(['titan.shopify.rate_limit.max_retries' => 0]);

        $connection = $this->createShopifyConnection();
        $syncRun = SyncRun::query()->create([
            'connection_id' => $connection->id,
            'type' => SyncRunType::Backfill,
            'status' => SyncStatus::Running,
        ]);

        app(SyncConnectionService::class)->sync(
            $connection,
            SyncRunType::Backfill,
            $syncRun->id,
            null,
            0,
            0,
        );

        $syncRun->refresh();
        $this->assertNotSame(SyncStatus::Failed, $syncRun->status);

        Queue::assertPushed(SyncConnectionJob::class, function (SyncConnectionJob $job) use ($syncRun) {
            return $job->syncRunId === $syncRun->id;
        });
    }

    protected function createShopifyConnection(): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-'.uniqid()])->id,
            'name' => 'Main',
            'slug' => 'main-'.uniqid(),
        ]);

        return Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify Store',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => [
                'shop_domain' => 'demo.myshopify.com',
                'access_token' => 'demo-token',
            ],
        ]);
    }
}
