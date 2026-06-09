<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\SearchConsoleConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SearchConsoleConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.google.client_id' => 'test-client-id',
            'titan.google.client_secret' => 'test-client-secret',
            'titan.search_console.chunk_days' => 7,
            'titan.search_console.row_limit' => 2,
            'titan.search_console.data_lag_days' => 3,
            'titan.search_console.backfill_months' => 1,
        ]);

        Carbon::setTestNow('2025-06-10');
    }

    public function test_validate_credentials_confirms_property_access(): void
    {
        $this->fakeGoogleApis([
            'sites' => [
                'siteEntry' => [
                    ['siteUrl' => 'https://example.com/', 'permissionLevel' => 'siteOwner'],
                ],
            ],
        ]);

        $connection = $this->makeConnection([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'refresh-token',
        ]);

        $result = app(SearchConsoleConnector::class)->validateCredentials($connection);

        $this->assertTrue($result->valid);
        $this->assertStringContainsString('https://example.com/', $result->message ?? '');
    }

    public function test_fetch_paginates_search_analytics_rows(): void
    {
        $queryResponses = [
            [
                'rows' => [
                    ['keys' => ['2025-06-01'], 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 4.2],
                    ['keys' => ['2025-06-02'], 'clicks' => 8, 'impressions' => 90, 'ctr' => 0.09, 'position' => 4.5],
                ],
            ],
            [
                'rows' => [
                    ['keys' => ['2025-06-03'], 'clicks' => 5, 'impressions' => 50, 'ctr' => 0.1, 'position' => 5.0],
                ],
            ],
        ];

        $this->fakeGoogleApis(['query_responses' => $queryResponses]);

        $connection = $this->makeConnection([
            'site_url' => 'https://example.com/',
            'refresh_token' => 'refresh-token',
        ]);

        $connector = app(SearchConsoleConnector::class);
        $first = $connector->fetch($connection, null);

        $this->assertCount(2, $first->records);
        $this->assertTrue($first->hasMore);
        $this->assertNotNull($first->nextCursor);
        $this->assertSame('search_daily', $first->records[0]['resource_type']);

        $second = $connector->fetch($connection, $first->nextCursor);

        $this->assertCount(1, $second->records);
        $this->assertTrue($second->hasMore);
    }

    /**
     * @param  array<string, mixed>  $credentials
     */
    protected function makeConnection(array $credentials): Connection
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        return new Connection([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'GSC',
            'connector_type' => ConnectorType::SearchConsole,
            'encrypted_credentials' => $credentials,
        ]);
    }

    /**
     * @param  array{sites?: array<string, mixed>, query_responses?: list<array<string, mixed>>}  $options
     */
    protected function fakeGoogleApis(array $options = []): void
    {
        $queryResponses = $options['query_responses'] ?? [
            ['rows' => []],
        ];
        $queryIndex = 0;

        Http::fake(function ($request) use ($options, &$queryIndex, $queryResponses) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-token'], 200);
            }

            if (str_ends_with($request->url(), '/sites') && $request->method() === 'GET') {
                return Http::response($options['sites'] ?? ['siteEntry' => []], 200);
            }

            if (str_contains($request->url(), '/searchAnalytics/query')) {
                $body = $queryResponses[$queryIndex] ?? ['rows' => []];
                $queryIndex++;

                return Http::response($body, 200);
            }

            return Http::response([], 404);
        });
    }
}
