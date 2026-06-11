<?php

namespace Tests\Unit;

use App\Enums\ConnectorBlueprintStatus;
use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\ConnectorBlueprint;
use App\Models\ConnectorBlueprintStream;
use App\Models\RawConnectorPayload;
use App\Support\ConnectorDashboardWidgetBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConnectorDashboardWidgetBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_payload_fields_prefer_synced_payload_over_mapping_targets(): void
    {
        [$blueprint, $connection] = $this->createShopwareBlueprint();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => 'order-1',
            'payload' => [
                'date' => '2026-06-01',
                'total' => 99.5,
                'order_date' => '2026-06-01T12:00:00+00:00',
                'status' => 'completed',
            ],
            'payload_hash' => hash('sha256', 'order-1'),
            'fetched_at' => now(),
        ]);

        $widgets = app(ConnectorDashboardWidgetBuilder::class)->defaultWidgets($blueprint, $connection->id);

        $this->assertNotSame([], $widgets);
        $this->assertStringContainsString('$.total', $widgets[0]['sql']);
        $this->assertStringNotContainsString('amountTotal', $widgets[0]['sql']);
    }

    public function test_payload_fields_use_raw_api_keys_when_payload_is_not_normalized(): void
    {
        [$blueprint, $connection] = $this->createShopwareBlueprint();

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => 'order-1',
            'payload' => [
                'amountTotal' => 125.50,
                'orderDateTime' => '2026-06-01T12:00:00+00:00',
                'stateMachineState' => ['technicalName' => 'completed'],
            ],
            'payload_hash' => hash('sha256', 'order-1'),
            'fetched_at' => now(),
        ]);

        $widgets = app(ConnectorDashboardWidgetBuilder::class)->defaultWidgets($blueprint, $connection->id);

        $this->assertStringContainsString('amountTotal', $widgets[0]['sql']);
        $this->assertStringContainsString('orderDateTime', $widgets[1]['sql']);
    }

    /**
     * @return array{0: ConnectorBlueprint, 1: Connection}
     */
    protected function createShopwareBlueprint(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme-widget-builder']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main-widget-builder',
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
            'response_mapping' => [
                'records_path' => 'data',
                'id_path' => 'id',
                'date_path' => 'orderDateTime',
                'fields' => [
                    ['source' => 'amountTotal', 'target' => 'total'],
                    ['source' => 'orderDateTime', 'target' => 'order_date'],
                    ['source' => 'stateMachineState.technicalName', 'target' => 'status'],
                ],
            ],
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'connector_blueprint_id' => $blueprint->id,
            'name' => 'Shopware',
            'connector_type' => ConnectorType::Dynamic,
            'encrypted_credentials' => ['client_id' => 'id', 'client_secret' => 'secret'],
        ]);

        return [$blueprint, $connection];
    }
}
