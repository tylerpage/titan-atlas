<?php

namespace Tests\Feature;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorType;
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
use App\Services\Client\SavedDashboardService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorDashboardRebuildTest extends TestCase
{
    use RefreshDatabase;

    public function test_rebuild_dashboard_uses_synced_payload_fields(): void
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-rebuild']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-rebuild',
        ]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $blueprint = ConnectorBlueprint::query()->create([
            'company_id' => $company->id,
            'client_dashboard_id' => $dashboard->id,
            'slug' => 'shopware',
            'label' => 'Shopware',
            'status' => ConnectorBlueprintStatus::Active,
            'dashboard_spec' => [
                'title' => 'Shopware Dashboard',
                'widgets' => [[
                    'prompt' => 'Total Sales Overview',
                    'sql' => "SELECT SUM((r.payload->>'total_price')::double precision) AS total_sales FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE r.resource_type = 'shopware_order' AND c.client_dashboard_id = :dashboard_id",
                    'visualization_type' => 'stat_card',
                ]],
                'created_report_ids' => [],
            ],
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
            'name' => 'Shopware',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'shopware_order',
            'external_id' => 'order-1',
            'payload' => [
                'id' => 'order-1',
                'amountTotal' => 125.50,
                'orderDateTime' => '2026-06-01T12:00:00+00:00',
                'lineItems' => [
                    ['productId' => 'sku-1'],
                ],
            ],
            'payload_hash' => hash('sha256', 'order-1'),
            'fetched_at' => now(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.connections.rebuild-dashboard', $connection))
            ->assertRedirect()
            ->assertSessionHas('status');

        $blueprint->refresh();
        $report = AnalyticsReport::query()->first();

        $this->assertNotNull($report);
        $this->assertStringContainsString('amountTotal', $report->sql);
        $this->assertSame('total_sales', $report->visualization_config['value_column']);
        $this->assertSame('currency', $report->visualization_config['format']);

        $board = SavedDashboard::query()->first();
        $this->assertNotNull($board);
        $this->assertSame(3, SavedDashboardBlock::query()->count());

        $resolved = app(SavedDashboardService::class)->resolveBoard(
            $board,
            $dashboard,
            Carbon::now()->subDays(29)->startOfDay(),
            Carbon::now()->endOfDay(),
        );

        $this->assertCount(3, $resolved['blocks']);
        $this->assertSame('$125.50', $resolved['blocks'][0]['text']);
    }
}
