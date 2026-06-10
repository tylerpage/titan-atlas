<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorType;
use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\RawConnectorPayload;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use App\Services\Analytics\DynamicConnectorDashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicConnectorDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_dashboard_shows_dynamic_connector_widgets_instead_of_placeholder(): void
    {
        [$dashboard, $connection, $client] = $this->createDynamicSetup();

        $this->actingAs($client)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'connection' => $connection->id,
                'tab' => 'data',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('connections.0.connector_label', 'Shopware')
                ->where('connectorData.kind', 'dynamic')
                ->has('connectorData.blocks', 1)
                ->where('connectorData.blocks.0.text', '$125.50')
            );
    }

    public function test_dynamic_connector_dashboard_service_returns_null_without_saved_board(): void
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-dynamic-null']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-dynamic-null',
        ]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Active,
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'id'],
        ]);

        $this->assertNull(app(DynamicConnectorDashboardService::class)->dataFor(
            $dashboard,
            $connection,
            'last_30_days',
            null,
            \App\Enums\DateComparison::None,
        ));
    }

    /**
     * @return array{0: ClientDashboard, 1: Connection, 2: User}
     */
    protected function createDynamicSetup(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-dynamic']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-dynamic',
        ]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Active,
        ]);

        ConnectorBlueprintStream::query()->create([
            'connector_blueprint_id' => $blueprint->id,
            'stream_key' => 'orders',
            'resource_type' => 'shopware_order',
            'path_template' => '/api/search/order',
            'http_method' => 'POST',
            'enabled' => true,
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware Store',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'shopware_order',
            'external_id' => 'order-1',
            'payload' => [
                'amountTotal' => 125.50,
                'orderDateTime' => now()->subDays(3)->toIso8601String(),
            ],
            'payload_hash' => hash('sha256', 'order-1'),
            'fetched_at' => now(),
        ]);

        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'created_by' => $client->id,
            'prompt' => 'Total Sales Overview',
            'sql' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.amountTotal') AS REAL)), 0) AS total_sales FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'shopware_order' AND r.connection_id = :connection_id",
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [
                'header' => 'Total Sales Overview',
                'value_column' => 'total_sales',
                'format' => 'currency',
                'connection_id' => $connection->id,
            ],
        ]);

        $board = SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Shopware Dashboard',
            'created_by' => $client->id,
        ]);

        SavedDashboardBlock::query()->create([
            'saved_dashboard_id' => $board->id,
            'analytics_report_id' => $report->id,
            'sort_order' => 1,
        ]);

        $blueprint->update([
            'dashboard_spec' => [
                'title' => 'Shopware Dashboard',
                'saved_dashboard_id' => $board->id,
                'created_report_ids' => [$report->id],
            ],
        ]);

        return [$dashboard, $connection, $client];
    }
}
