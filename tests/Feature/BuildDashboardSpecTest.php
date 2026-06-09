<?php

namespace Tests\Feature;

use App\Agents\ReportingAgentContext;
use App\Ai\Tools\BuildDashboardSpecTool;
use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class BuildDashboardSpecTest extends TestCase
{
    use RefreshDatabase;

    public function test_build_dashboard_spec_creates_reports_and_pins_to_saved_dashboard(): void
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $user = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($user);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1001',
            'payload' => ['date' => '2025-06-01', 'total' => 500],
            'payload_hash' => hash('sha256', '1001'),
            'fetched_at' => now(),
        ]);

        $session = AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => AnalyticsReportSessionStatus::Active,
        ]);

        $context = new ReportingAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $user,
            previewStartDate: Carbon::parse('2025-06-01')->startOfDay(),
            previewEndDate: Carbon::parse('2025-06-30')->endOfDay(),
        );

        $orderSql = "SELECT COUNT(*) AS orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date";
        $revenueSql = "SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.total') AS REAL)), 0) AS revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date";

        $tool = app(BuildDashboardSpecTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request([
            'title' => 'Executive Summary',
            'saved_dashboard_title' => 'Q2 Board',
            'widgets' => [
                [
                    'prompt' => 'Total orders',
                    'sql' => $orderSql,
                    'visualization_type' => 'stat_card',
                    'visualization_config' => ['header' => 'Orders', 'format' => 'number', 'value_column' => 'orders'],
                ],
                [
                    'prompt' => 'Total revenue',
                    'sql' => $revenueSql,
                    'visualization_type' => 'stat_card',
                    'visualization_config' => ['header' => 'Revenue', 'format' => 'currency', 'value_column' => 'revenue'],
                ],
            ],
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['reports']);
        $this->assertSame(2, AnalyticsReport::query()->count());
        $this->assertSame(1, SavedDashboard::query()->count());
        $this->assertSame(2, SavedDashboardBlock::query()->count());
        $this->assertNotNull($context->lastDashboardSpec);
    }
}
