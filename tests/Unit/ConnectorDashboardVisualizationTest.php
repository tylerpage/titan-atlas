<?php

namespace Tests\Unit;

use App\Enums\ReportVisualizationType;
use App\Support\ConnectorDashboardVisualization;
use Tests\TestCase;

class ConnectorDashboardVisualizationTest extends TestCase
{
    public function test_normalizes_common_aliases(): void
    {
        $this->assertSame(ReportVisualizationType::StatCard, ConnectorDashboardVisualization::normalize('number'));
        $this->assertSame(ReportVisualizationType::Table, ConnectorDashboardVisualization::normalize('bar_chart'));
        $this->assertSame(ReportVisualizationType::LineChart, ConnectorDashboardVisualization::normalize('chart'));
    }

    public function test_validate_widget_sql_warns_on_missing_dashboard_scope_and_json_access(): void
    {
        $warnings = ConnectorDashboardVisualization::validateWidgetSql([
            ['sql' => "SELECT total FROM orders WHERE resource_type = 'orders'"],
        ]);

        $this->assertNotEmpty($warnings);
        $this->assertTrue(collect($warnings)->contains(fn ($warning) => str_contains($warning, ':dashboard_id')));
        $this->assertTrue(collect($warnings)->contains(fn ($warning) => str_contains($warning, 'raw_connector_payloads')));
    }
}
