<?php

namespace Tests\Feature;

use App\Agents\ReportingAgentContext;
use App\Ai\Tools\CreateMetricDefinitionTool;
use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AnalyticsEngineerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_metric_definition_tool_is_available_to_admin_agent_only(): void
    {
        $adminAgentTools = array_map(
            fn ($tool) => $tool::class,
            iterator_to_array((new \App\Ai\Agents\ReportingAgent(
                $this->makeContext(UserRole::Admin, 'admin-co'),
                app(\App\Agents\ReportingPromptBuilder::class),
                app(),
            ))->tools()),
        );

        $clientAgentTools = array_map(
            fn ($tool) => $tool::class,
            iterator_to_array((new \App\Ai\Agents\ClientReportingAgent(
                $this->makeContext(UserRole::Client, 'client-co'),
                app(\App\Agents\ClientReportingPromptBuilder::class),
                app(),
            ))->tools()),
        );

        $this->assertContains(CreateMetricDefinitionTool::class, $adminAgentTools);
        $this->assertNotContains(CreateMetricDefinitionTool::class, $clientAgentTools);
    }

    public function test_admin_can_create_custom_metric_via_tool(): void
    {
        $context = $this->makeContext(UserRole::Admin);
        $tool = app(CreateMetricDefinitionTool::class, ['context' => $context]);

        $sql = "SELECT COUNT(*) AS custom_orders FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = 'order' AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date";

        $result = json_decode($tool->handle(new Request([
            'slug' => 'custom_orders',
            'name' => 'Custom Orders',
            'description' => 'Dashboard-specific order count',
            'sql_template' => $sql,
            'visualization_type' => 'stat_card',
            'visualization_config' => ['header' => 'Orders', 'format' => 'number', 'value_column' => 'custom_orders'],
            'connector_types' => ['shopify'],
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('metric_definitions', [
            'slug' => 'custom_orders',
            'client_dashboard_id' => $context->dashboard->id,
        ]);
    }

    protected function makeContext(UserRole $role, string $companySlug = 'acme'): ReportingAgentContext
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => $companySlug]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $user = User::factory()->create(['role' => $role]);

        Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
        ]);

        $session = AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => AnalyticsReportSessionStatus::Active,
        ]);

        return new ReportingAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $user,
            previewStartDate: Carbon::parse('2025-06-01')->startOfDay(),
            previewEndDate: Carbon::parse('2025-06-30')->endOfDay(),
        );
    }
}
