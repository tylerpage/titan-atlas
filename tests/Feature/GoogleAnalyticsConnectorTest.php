<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Ingestion\Connectors\GoogleAnalyticsConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GoogleAnalyticsConnectorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'titan.google.client_id' => 'test-client-id',
            'titan.google.client_secret' => 'test-client-secret',
            'titan.google_analytics.chunk_days' => 7,
            'titan.google_analytics.row_limit' => 2,
            'titan.google_analytics.data_lag_days' => 2,
            'titan.google_analytics.backfill_months' => 1,
        ]);

        Carbon::setTestNow('2025-06-10');
    }

    public function test_validate_credentials_confirms_property_access(): void
    {
        $this->fakeGoogleAnalyticsApis();

        $connection = $this->makeConnection([
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $result = app(GoogleAnalyticsConnector::class)->validateCredentials($connection);

        $this->assertTrue($result->valid);
        $this->assertStringContainsString('123456789', $result->message ?? '');
    }

    public function test_fetch_emits_traffic_daily_records(): void
    {
        $this->fakeGoogleAnalyticsApis([
            'report_responses' => [
                [
                    'rows' => [
                        [
                            'dimensionValues' => [['value' => '20250601']],
                            'metricValues' => [['value' => '100'], ['value' => '80'], ['value' => '120']],
                        ],
                    ],
                ],
            ],
        ]);

        $connection = $this->makeConnection([
            'property_id' => '123456789',
            'refresh_token' => 'refresh-token',
        ]);

        $cursor = 'ga4:'.json_encode([
            'stream' => 'traffic_daily',
            'start_date' => '2025-06-01',
            'end_date' => '2025-06-07',
            'start_row' => 0,
        ], JSON_THROW_ON_ERROR);

        $result = app(GoogleAnalyticsConnector::class)->fetch($connection, $cursor);

        $this->assertCount(1, $result->records);
        $this->assertSame('traffic_daily', $result->records[0]['resource_type']);
        $this->assertSame('2025-06-01', $result->records[0]['payload']['date']);
        $this->assertSame(100.0, $result->records[0]['payload']['visitors']);
        $this->assertSame(80.0, $result->records[0]['payload']['active_users']);
        $this->assertSame(120.0, $result->records[0]['payload']['sessions']);
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
            'name' => 'GA4',
            'connector_type' => ConnectorType::GoogleAnalytics,
            'encrypted_credentials' => $credentials,
        ]);
    }

    /**
     * @param  array{report_responses?: list<array<string, mixed>>}  $options
     */
    protected function fakeGoogleAnalyticsApis(array $options = []): void
    {
        $reportResponses = $options['report_responses'] ?? [
            ['rows' => []],
        ];
        $reportIndex = 0;

        Http::fake(function ($request) use (&$reportIndex, $reportResponses) {
            if (str_contains($request->url(), 'oauth2.googleapis.com/token')) {
                return Http::response(['access_token' => 'access-token'], 200);
            }

            if (str_contains($request->url(), 'analyticsadmin.googleapis.com/v1beta/accountSummaries')) {
                return Http::response([
                    'accountSummaries' => [
                        [
                            'displayName' => 'Acme',
                            'propertySummaries' => [
                                [
                                    'property' => 'properties/123456789',
                                    'displayName' => 'Main Site',
                                ],
                            ],
                        ],
                    ],
                ], 200);
            }

            if (str_contains($request->url(), ':runReport')) {
                $body = $reportResponses[$reportIndex] ?? ['rows' => []];
                $reportIndex++;

                return Http::response($body, 200);
            }

            return Http::response([], 404);
        });
    }
}
