<?php

namespace Tests\Feature;

use App\Agents\ReportingAgentContext;
use App\Ai\Tools\DescribeConnectorSchemaTool;
use App\Ai\Tools\ExplainMetricTool;
use App\Ai\Tools\GenerateDocumentationTool;
use App\Ai\Tools\ListMetricDefinitionsTool;
use App\Ai\Tools\RunDataQualityChecksTool;
use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class AnalyticsEngineerToolsTest extends TestCase
{
    use RefreshDatabase;

    protected function createContext(): ReportingAgentContext
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $user = User::factory()->create(['role' => UserRole::Admin]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Shopify',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
            'last_synced_at' => now(),
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

        return new ReportingAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $user,
            previewStartDate: Carbon::parse('2025-06-01')->startOfDay(),
            previewEndDate: Carbon::parse('2025-06-30')->endOfDay(),
        );
    }

    public function test_describe_connector_schema_returns_shopify_entities(): void
    {
        $context = $this->createContext();
        $tool = app(DescribeConnectorSchemaTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request(['connector_type' => 'shopify'])), true);

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($result['connector_entities']);
    }

    public function test_list_metric_definitions_returns_builtins(): void
    {
        $context = $this->createContext();
        $tool = app(ListMetricDefinitionsTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request([])), true);

        $this->assertTrue($result['success']);
        $this->assertGreaterThanOrEqual(7, $result['count']);
    }

    public function test_explain_metric_returns_sql_template(): void
    {
        $context = $this->createContext();
        $tool = app(ExplainMetricTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request(['slug' => 'revenue'])), true);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('SELECT', $result['metric']['sql_template']);
    }

    public function test_run_data_quality_checks_returns_structured_results(): void
    {
        $context = $this->createContext();
        $tool = app(RunDataQualityChecksTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request([])), true);

        $this->assertTrue($result['success']);
        $this->assertArrayHasKey('checks', $result);
        $this->assertArrayHasKey('summary', $result);
        $this->assertNotNull($context->lastQualityReport);
    }

    public function test_generate_documentation_for_metric(): void
    {
        $context = $this->createContext();
        $tool = app(GenerateDocumentationTool::class, ['context' => $context]);
        $result = json_decode($tool->handle(new Request([
            'subject' => 'metric',
            'identifier' => 'revenue',
        ])), true);

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('Gross Revenue', $result['markdown']);
        $this->assertNotNull($context->lastDocumentation);
    }
}
