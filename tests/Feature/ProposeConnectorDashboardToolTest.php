<?php

namespace Tests\Feature;

use App\Agents\ConnectorBuilderAgentContext;
use App\Ai\Tools\ConnectorBuilder\ListBlueprintAnalyticsSchemaTool;
use App\Ai\Tools\ConnectorBuilder\ProposeConnectorDashboardTool;
use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\ConnectorBuilderSession;
use App\Models\RawConnectorPayload;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ProposeConnectorDashboardToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_propose_connector_dashboard_creates_reports_and_saved_dashboard(): void
    {
        [$dashboard, $blueprint, $connection, $session, $admin] = $this->createSetup();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'orders',
            'external_id' => 'order-1',
            'payload' => ['date' => now()->subDays(3)->toDateString(), 'total' => 120],
            'payload_hash' => hash('sha256', 'order-1'),
            'fetched_at' => now(),
        ]);

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $admin,
            blueprint: $blueprint,
            connection: $connection,
        );

        $countSql = "SELECT COUNT(*) AS total_orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'orders' AND r.connection_id = :connection_id";
        $revenueSql = "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.total') AS REAL)), 0) AS total_revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'orders' AND r.connection_id = :connection_id AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date";

        $tool = app(ProposeConnectorDashboardTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request([
            'title' => 'Shopware Dashboard',
            'saved_dashboard_title' => 'Shopware Analytics',
            'widgets' => [
                [
                    'prompt' => 'Total orders',
                    'sql' => $countSql,
                    'visualization_type' => 'number',
                    'visualization_config' => ['header' => 'Orders', 'format' => 'number', 'value_column' => 'total_orders'],
                ],
                [
                    'prompt' => 'Total revenue',
                    'sql' => $revenueSql,
                    'visualization_type' => 'bar_chart',
                    'visualization_config' => ['title' => 'Revenue', 'format' => 'currency', 'value_column' => 'total_revenue'],
                ],
            ],
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertSame(2, AnalyticsReport::query()->count());
        $this->assertSame(1, SavedDashboard::query()->count());
        $this->assertSame(2, SavedDashboardBlock::query()->count());
        $this->assertSame('Shopware Analytics', $result['saved_dashboard_title']);
        $this->assertCount(2, $result['created_reports']);
        $this->assertSame('stat_card', AnalyticsReport::query()->first()->visualization_type->value);

        $blueprint->refresh();
        $currentSpec = app(ConnectorBlueprintDashboardVersionService::class)->currentSpec($blueprint, $dashboard);
        $this->assertSame(2, count($currentSpec['created_report_ids'] ?? []));
        $this->assertSame(SavedDashboard::query()->value('id'), $currentSpec['saved_dashboard_id']);
        $this->assertSame(2, count($blueprint->dashboard_spec['widgets'] ?? []));
        $this->assertNotNull($context->lastDashboardSpec);
    }

    public function test_list_blueprint_analytics_schema_returns_stream_fields(): void
    {
        [$dashboard, $blueprint, $connection, $session, $admin] = $this->createSetup();

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $admin,
            blueprint: $blueprint,
            connection: $connection,
        );

        $tool = app(ListBlueprintAnalyticsSchemaTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request([])), true);

        $this->assertTrue($result['success']);
        $this->assertSame('orders', $result['schema']['streams'][0]['resource_type']);
        $this->assertContains('total', $result['schema']['streams'][0]['payload_fields']);
        $this->assertArrayHasKey('total_count', $result['schema']['sql_templates']);
    }

    public function test_propose_connector_dashboard_fails_without_connection(): void
    {
        [$dashboard, $blueprint, , $session, $admin] = $this->createSetup(createConnection: false);

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $admin,
            blueprint: $blueprint,
        );

        $tool = app(ProposeConnectorDashboardTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request([
            'widgets' => [[
                'prompt' => 'Total orders',
                'sql' => "SELECT COUNT(*) AS total_orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'orders'",
                'visualization_type' => 'stat_card',
            ]],
        ])), true);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Create a connection first', $result['error']);
    }

    /**
     * @return array{0: ClientDashboard, 1: ConnectorBlueprint, 2: Connection|null, 3: ConnectorBuilderSession, 4: User}
     */
    protected function createSetup(bool $createConnection = true): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-propose']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-propose',
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $admin->id,
            'status' => ConnectorBuilderSessionStatus::Active,
            'title' => 'Shopware',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'client_dashboard_id' => $dashboard->id,
            'connector_builder_session_id' => $session->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Ready,
            'original_prompt' => 'Connect Shopware orders',
            'transform_config' => [
                'orders' => [
                    'metrics' => [
                        ['key' => 'total', 'value_path' => 'total'],
                    ],
                ],
            ],
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'orders',
            'path_template' => '/api/search/order',
            'http_method' => 'POST',
            'enabled' => true,
            'response_mapping' => [
                'records_path' => 'data',
                'id_path' => 'id',
                'date_path' => 'date',
                'fields' => [
                    ['source' => 'total', 'target' => 'total'],
                    ['source' => 'date', 'target' => 'date'],
                ],
            ],
        ]);

        $connection = null;

        if ($createConnection) {
            $connection = Connection::query()->create([
                'client_dashboard_id' => $dashboard->id,
                'connector_blueprint_id' => $blueprint->id,
                'name' => 'Shopware',
                'connector_type' => ConnectorType::Dynamic,
                'encrypted_credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
            ]);
        }

        return [$dashboard, $blueprint, $connection, $session, $admin];
    }
}
