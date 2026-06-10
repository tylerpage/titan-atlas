<?php

namespace Tests\Feature;

use App\Agents\ConnectorBuilderAgentContext;
use App\Ai\Tools\ConnectorBuilder\ProposeConnectorDashboardTool;
use App\Ai\Tools\ConnectorBuilder\RevertConnectorDashboardTool;
use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorBuilderSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintDashboardVersion;
use App\Models\ConnectorBlueprintStream;
use App\Models\ConnectorBuilderSession;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use App\Services\ConnectorBuilder\ConnectorBlueprintDashboardVersionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class ConnectorBlueprintDashboardVersionTest extends TestCase
{
    use RefreshDatabase;

    public function test_propose_dashboard_creates_version_snapshot_before_update(): void
    {
        [$dashboard, $blueprint, $connection, $session, $admin] = $this->createSetup();

        $blueprint->update([
            'dashboard_spec' => [
                'title' => 'Old Dashboard',
                'widgets' => [],
                'created_report_ids' => [],
            ],
        ]);

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $admin,
            blueprint: $blueprint,
            connection: $connection,
        );

        $countSql = "SELECT COUNT(*) AS total_orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'orders' AND r.connection_id = :connection_id";

        app(ProposeConnectorDashboardTool::class, ['context' => $context])->handle(new Request([
            'title' => 'Shopware Dashboard',
            'widgets' => [[
                'prompt' => 'Total orders',
                'sql' => $countSql,
                'visualization_type' => 'stat_card',
            ]],
        ]));

        $blueprint->refresh();

        $this->assertSame(2, ConnectorBlueprintDashboardVersion::query()->count());
        $this->assertSame(
            'Old Dashboard',
            ConnectorBlueprintDashboardVersion::query()->where('version_number', 1)->value('dashboard_spec')['title'],
        );
        $this->assertSame(
            'Shopware Dashboard',
            app(ConnectorBlueprintDashboardVersionService::class)->currentSpec($blueprint, $dashboard)['title'],
        );
    }

    public function test_revert_dashboard_restores_prior_version(): void
    {
        [$dashboard, $blueprint, $connection, $session, $admin] = $this->createSetup();

        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'created_by' => $admin->id,
            'prompt' => 'Orders',
            'sql' => 'SELECT 1 AS orders',
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [
                'header' => 'Orders',
                'value_column' => 'orders',
            ],
        ]);

        $board = SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Board',
            'created_by' => $admin->id,
        ]);

        $blueprint->update([
            'dashboard_spec' => [
                'title' => 'Current',
                'widgets' => [],
                'created_report_ids' => [$report->id],
                'saved_dashboard_id' => $board->id,
                'pinned_blocks' => [],
            ],
        ]);

        $version = ConnectorBlueprintDashboardVersion::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'client_dashboard_id' => $dashboard->id,
            'version_number' => 1,
            'dashboard_spec' => [
                'title' => 'Previous',
                'widgets' => [],
                'created_report_ids' => [$report->id],
                'saved_dashboard_id' => $board->id,
                'pinned_blocks' => [
                    ['report_id' => $report->id, 'title' => 'Orders', 'column_span' => 1],
                ],
            ],
            'created_by' => $admin->id,
        ]);

        SavedDashboardBlock::query()->create([
            'saved_dashboard_id' => $board->id,
            'analytics_report_id' => $report->id,
            'sort_order' => 1,
        ]);

        $context = new ConnectorBuilderAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $admin,
            blueprint: $blueprint,
            connection: $connection,
        );

        $result = json_decode(app(RevertConnectorDashboardTool::class, ['context' => $context])->handle(new Request([
            'version_id' => $version->id,
        ])), true);

        $this->assertTrue($result['success']);
        $blueprint->refresh();
        $this->assertSame('Previous', $blueprint->dashboard_spec['title']);
        $this->assertSame(1, SavedDashboardBlock::query()->where('saved_dashboard_id', $board->id)->count());
    }

    /**
     * @return array{0: ClientDashboard, 1: ConnectorBlueprint, 2: Connection, 3: ConnectorBuilderSession, 4: User}
     */
    protected function createSetup(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-version']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-version',
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $session = ConnectorBuilderSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $admin->id,
            'status' => ConnectorBuilderSessionStatus::Active,
        ]);
        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'client_dashboard_id' => $dashboard->id,
            'connector_builder_session_id' => $session->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Ready,
        ]);
        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'orders',
            'path_template' => '/api/search/order',
            'http_method' => 'POST',
            'enabled' => true,
        ]);
        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'id'],
        ]);

        return [$dashboard, $blueprint, $connection, $session, $admin];
    }
}
