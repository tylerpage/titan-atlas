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
use App\Services\Client\SavedDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DynamicConnectorDashboardConnectionScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_dynamic_dashboard_scopes_widgets_to_active_connection(): void
    {
        [$dashboard, $blueprint, $connectionA, $connectionB] = $this->createSetup();

        RawConnectorPayload::query()->create([
            'connection_id' => $connectionA->id,
            'resource_type' => 'order',
            'external_id' => 'order-a',
            'payload' => ['date' => now()->subDay()->toDateString(), 'total' => 10],
            'payload_hash' => hash('sha256', 'order-a'),
            'fetched_at' => now(),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connectionB->id,
            'resource_type' => 'order',
            'external_id' => 'order-b',
            'payload' => ['date' => now()->subDay()->toDateString(), 'total' => 250],
            'payload_hash' => hash('sha256', 'order-b'),
            'fetched_at' => now(),
        ]);

        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'created_by' => User::factory()->create()->id,
            'prompt' => 'Total Sales Overview',
            'sql' => "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.total') AS REAL)), 0) AS total_sales FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND r.connection_id = :connection_id",
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [
                'header' => 'Total Sales Overview',
                'value_column' => 'total_sales',
                'format' => 'currency',
                'connection_id' => $connectionA->id,
            ],
        ]);

        $board = SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Shopware Dashboard',
            'created_by' => User::factory()->create()->id,
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
            ],
        ]);

        $resolved = app(DynamicConnectorDashboardService::class)->dataFor(
            $dashboard,
            $connectionB,
            'last_30_days',
            null,
            \App\Enums\DateComparison::None,
        );

        $this->assertSame('$250.00', $resolved['blocks'][0]['text']);

        $legacy = app(SavedDashboardService::class)->resolveBoard(
            $board,
            $dashboard,
            Carbon::now()->subDays(29)->startOfDay(),
            Carbon::now()->endOfDay(),
        );

        $this->assertSame('$10.00', $legacy['blocks'][0]['text']);
    }

    /**
     * @return array{0: ClientDashboard, 1: ConnectorBlueprint, 2: Connection, 3: Connection}
     */
    protected function createSetup(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-conn-scope']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-conn-scope',
        ]);

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
            'resource_type' => 'order',
            'path_template' => '/api/search/order',
            'http_method' => 'POST',
            'enabled' => true,
        ]);

        $connectionA = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware A',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'a'],
        ]);

        $connectionB = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware B',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'b'],
        ]);

        return [$dashboard, $blueprint, $connectionA, $connectionB];
    }
}
