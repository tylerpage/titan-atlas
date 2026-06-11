<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Ingestion\Connectors\DynamicConnector;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorApiLog;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\User;
use App\Services\Ingestion\ConnectorApiLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ConnectorApiLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_connector_fetch_records_api_log_with_response_body(): void
    {
        Http::fake([
            'https://api.example.com/*' => Http::response([
                'results' => [[
                    'id' => '1',
                    'date' => '2026-06-01',
                    'amount' => 42,
                ]],
            ], 200),
        ]);

        [, $blueprint, $connection] = $this->createExampleSetup();

        app(DynamicConnector::class)->fetch($connection);

        $log = ConnectorApiLog::query()->first();

        $this->assertNotNull($log);
        $this->assertSame($connection->id, $log->connection_id);
        $this->assertSame($blueprint->id, $log->connector_blueprint_id);
        $this->assertSame('sync', $log->context->value);
        $this->assertSame(200, $log->status_code);
        $this->assertStringContainsString('"amount":42', (string) $log->response_body);
        $this->assertStringContainsString('api.example.com', $log->url);
    }

    public function test_prune_command_deletes_logs_older_than_retention_window(): void
    {
        config(['titan.connector_api_logs.retention_hours' => 48]);

        $old = ConnectorApiLog::query()->create([
            'connector_type' => 'dynamic',
            'context' => 'sync',
            'method' => 'GET',
            'url' => 'https://api.example.com/items',
            'status_code' => 200,
            'duration_ms' => 10,
        ]);
        $old->forceFill([
            'created_at' => now()->subHours(72),
            'updated_at' => now()->subHours(72),
        ])->save();

        ConnectorApiLog::query()->create([
            'connector_type' => 'dynamic',
            'context' => 'sync',
            'method' => 'GET',
            'url' => 'https://api.example.com/items',
            'status_code' => 200,
            'duration_ms' => 10,
        ]);

        $this->artisan('titan:prune-connector-api-logs')->assertSuccessful();

        $this->assertSame(1, ConnectorApiLog::query()->count());
    }

    public function test_admin_can_filter_connector_api_logs(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        [, $blueprint, $connection] = $this->createExampleSetup();

        ConnectorApiLog::query()->create([
            'connection_id' => $connection->id,
            'connector_blueprint_id' => $blueprint->id,
            'connector_type' => 'dynamic',
            'context' => 'sync',
            'method' => 'GET',
            'url' => 'https://api.example.com/items',
            'status_code' => 500,
            'duration_ms' => 25,
            'stream_key' => 'items',
            'error_message' => 'API request failed',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.connector-api-logs.index', [
                'connection_id' => $connection->id,
                'status' => 'failed',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/ConnectorApiLogs/Index')
                ->has('logs.data', 1)
                ->where('logs.data.0.connection.id', $connection->id));

        $this->actingAs($admin)
            ->get(route('admin.connector-api-logs.show', ConnectorApiLog::query()->first()))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Admin/ConnectorApiLogs/Show'));
    }

    public function test_logging_can_be_disabled_via_config(): void
    {
        config(['titan.connector_api_logs.enabled' => false]);

        Http::fake([
            'https://api.example.com/*' => Http::response(['results' => []], 200),
        ]);

        [, , $connection] = $this->createExampleSetup();

        app(DynamicConnector::class)->fetch($connection);

        $this->assertSame(0, ConnectorApiLog::query()->count());
    }

    public function test_sensitive_query_params_are_redacted_in_logged_url(): void
    {
        $service = app(ConnectorApiLogService::class);

        Http::fake([
            'https://api.example.com/*' => Http::response(['results' => []], 200),
        ]);

        [, $blueprint] = $this->createExampleSetup();
        $response = Http::get('https://api.example.com/items?api_key=secret-value&limit=10');

        $service->record(
            blueprint: $blueprint,
            method: 'GET',
            url: 'https://api.example.com/items?api_key=secret-value&limit=10',
            queryParams: ['api_key' => 'secret-value', 'limit' => 10],
            body: [],
            response: $response,
            durationMs: 5,
        );

        $log = ConnectorApiLog::query()->firstOrFail();

        $this->assertStringNotContainsString('secret-value', $log->url);
        $this->assertSame('[redacted]', $log->request_query['api_key']);
    }

    /**
     * @return array{0: ClientDashboard, 1: ConnectorBlueprint, 2: Connection}
     */
    protected function createExampleSetup(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-api-log']);
        $dashboard = ClientDashboard::query()->create(['company_id' => $company->id, 'name' => 'Main', 'slug' => 'main-api-log']);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'slug' => 'example-api',
            'label' => 'Example API',
            'status' => ConnectorBlueprintStatus::Ready,
            'auth_config' => ['type' => 'bearer', 'credential_key' => 'access_token'],
            'credential_schema' => [
                ['key' => 'access_token', 'label' => 'Access token', 'type' => 'password'],
            ],
            'sync_config' => ['base_url' => 'https://api.example.com', 'test_endpoint' => '/items?limit=1'],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'items',
            'resource_type' => 'example_item',
            'path_template' => '/items',
            'response_mapping' => [
                'records_path' => 'results',
                'id_path' => 'id',
                'date_path' => 'date',
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_type' => ConnectorType::Dynamic,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Example API',
            'encrypted_credentials' => ['access_token' => 'secret-token-value'],
        ]);

        return [$dashboard, $blueprint, $connection];
    }
}
