<?php

namespace Tests\Feature;

use App\Agents\ClientReportingPromptBuilder;
use App\Agents\PromptSkillRouter;
use App\Agents\ReportingAgentContext;
use App\Agents\ReportingPromptBuilder;
use App\Agents\TitanAiPromptSections;
use App\Ai\Agents\ClientReportingAgent;
use App\Ai\Agents\ReportingAgent;
use App\Ai\Tools\PreviewReportQueryTool;
use App\Ai\Tools\SaveAnalyticsReportTool;
use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ConnectorType;
use App\Enums\ReportVisualizationType;
use App\Jobs\GenerateReportResponseJob;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportMessage;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\User;
use App\Services\AI\ReportingAgentService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class TitanAiEfficiencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_prompt_uses_compact_schema_not_full_json_tables(): void
    {
        $prompt = app(ClientReportingPromptBuilder::class)->systemPrompt($this->context());

        $this->assertStringContainsString('## Schema summary', $prompt);
        $this->assertStringContainsString('ListAnalyticsSchemaTool', $prompt);
        $this->assertStringNotContainsString('"tables"', $prompt);
        $this->assertStringNotContainsString('connector_entities', $prompt);
    }

    public function test_client_prompt_uses_canonical_tool_names(): void
    {
        $prompt = app(ClientReportingPromptBuilder::class)->systemPrompt($this->context());

        $this->assertStringContainsString('SaveAnalyticsReportTool', $prompt);
        $this->assertStringContainsString('PreviewReportQueryTool', $prompt);
        $this->assertStringContainsString('PinReportToSavedDashboardTool', $prompt);
        $this->assertStringNotContainsString('save_analytics_report', $prompt);
    }

    public function test_visualization_examples_omitted_when_session_has_reports(): void
    {
        $context = $this->context();

        AnalyticsReport::query()->create([
            'client_dashboard_id' => $context->dashboard->id,
            'analytics_report_session_id' => $context->session->id,
            'created_by' => $context->user->id,
            'prompt' => 'Revenue trend',
            'sql' => 'SELECT 1 AS revenue',
            'visualization_type' => ReportVisualizationType::LineChart,
            'visualization_config' => ['title' => 'Revenue'],
        ]);

        $prompt = app(ClientReportingPromptBuilder::class)->systemPrompt($context);

        $this->assertStringNotContainsString('## Example patterns', $prompt);
    }

    public function test_recent_session_reports_omit_sql_and_cap_at_three(): void
    {
        $context = $this->context();

        foreach (range(1, 4) as $index) {
            AnalyticsReport::query()->create([
                'client_dashboard_id' => $context->dashboard->id,
                'analytics_report_session_id' => $context->session->id,
                'created_by' => $context->user->id,
                'prompt' => "Report {$index}",
                'sql' => 'SELECT secret_sql_column FROM connections',
                'visualization_type' => ReportVisualizationType::Table,
                'visualization_config' => ['title' => "Report {$index}"],
            ]);
        }

        $section = app(TitanAiPromptSections::class)->recentSessionReports($context);

        $this->assertStringNotContainsString('secret_sql_column', $section);
        $this->assertStringNotContainsString('SQL:', $section);
        $this->assertStringContainsString('Report 4', $section);
        $this->assertStringNotContainsString('Report 1', $section);
    }

    public function test_admin_prompt_includes_metric_skill_when_message_mentions_kpi(): void
    {
        $context = $this->context(currentMessage: 'What is our ROAS KPI?');

        $prompt = app(ReportingPromptBuilder::class)->systemPrompt($context);

        $this->assertStringContainsString('## Skill: metrics', $prompt);
        $this->assertStringContainsString('ExplainMetricTool', $prompt);
    }

    public function test_admin_prompt_omits_dashboard_spec_skill_for_simple_data_question(): void
    {
        $context = $this->context(currentMessage: 'Show revenue by day');

        $prompt = app(ReportingPromptBuilder::class)->systemPrompt($context);

        $this->assertStringNotContainsString('## Skill: multi-widget dashboards', $prompt);
    }

    public function test_prompt_skill_router_detects_dashboard_spec_intent(): void
    {
        $router = app(PromptSkillRouter::class);

        $this->assertTrue($router->shouldIncludeDashboardSpecSkill('Build a dashboard with multiple widgets'));
        $this->assertFalse($router->shouldIncludeDashboardSpecSkill('Total revenue last week'));
    }

    public function test_client_prompt_includes_summary_skill_for_client_brief(): void
    {
        $context = $this->context(currentMessage: 'Can you write a client summary for our analytics during this timeframe?');

        $prompt = app(ClientReportingPromptBuilder::class)->systemPrompt($context);

        $this->assertStringContainsString('## Skill: period summaries', $prompt);
        $this->assertStringContainsString('2–4 short paragraphs', $prompt);
        $this->assertStringContainsString('trusted analytics partner', $prompt);
        $this->assertStringContainsString('ONE KPI table', $prompt);
    }

    public function test_client_prompt_uses_short_reply_for_simple_stat_question(): void
    {
        $context = $this->context(currentMessage: 'What was total revenue?');

        $prompt = app(ClientReportingPromptBuilder::class)->systemPrompt($context);

        $this->assertStringNotContainsString('## Skill: period summaries', $prompt);
        $this->assertStringContainsString('1–2 sentences of commentary only', $prompt);
    }

    public function test_preview_report_query_tool_returns_sample_rows(): void
    {
        $context = $this->seededContext();
        $tool = app(PreviewReportQueryTool::class, ['context' => $context]);

        $sql = <<<'SQL'
SELECT json_extract(r.payload, '$.date') AS date, SUM(CAST(json_extract(r.payload, '$.total') AS REAL)) AS revenue
FROM raw_connector_payloads r
JOIN connections c ON c.id = r.connection_id
WHERE c.client_dashboard_id = :dashboard_id
AND r.resource_type = 'order'
AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date
GROUP BY 1
ORDER BY 1
SQL;

        $result = json_decode($tool->handle(new Request(['sql' => $sql])), true);

        $this->assertTrue($result['success']);
        $this->assertContains('revenue', $result['columns']);
        $this->assertNotEmpty($result['rows']);
    }

    public function test_save_analytics_report_tool_persists_widget(): void
    {
        $context = $this->seededContext();
        $preview = app(PreviewReportQueryTool::class, ['context' => $context]);
        $save = app(SaveAnalyticsReportTool::class, ['context' => $context]);

        $sql = <<<'SQL'
SELECT json_extract(r.payload, '$.date') AS date, SUM(CAST(json_extract(r.payload, '$.total') AS REAL)) AS revenue
FROM raw_connector_payloads r
JOIN connections c ON c.id = r.connection_id
WHERE c.client_dashboard_id = :dashboard_id
AND r.resource_type = 'order'
AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date
GROUP BY 1
ORDER BY 1
SQL;

        $previewResult = json_decode($preview->handle(new Request(['sql' => $sql])), true);
        $this->assertTrue($previewResult['success']);

        $saveResult = json_decode($save->handle(new Request([
            'prompt' => 'Daily revenue',
            'sql' => $sql,
            'visualization_type' => 'line_chart',
            'visualization_config' => [
                'title' => 'Daily revenue',
                'date_column' => 'date',
                'value_column' => 'revenue',
                'format' => 'currency',
            ],
        ])), true);

        $this->assertTrue($saveResult['success']);
        $this->assertDatabaseHas('analytics_reports', [
            'analytics_report_session_id' => $context->session->id,
            'prompt' => 'Daily revenue',
            'visualization_type' => 'line_chart',
        ]);
        $this->assertNotNull($context->lastSavedReport);
    }

    public function test_generate_report_response_job_does_not_duplicate_user_message(): void
    {
        ClientReportingAgent::fake(['Saved your revenue chart.']);

        $context = $this->seededContext();
        $dashboard = $context->dashboard;
        $user = $context->user;
        $session = $context->session;

        app(ReportingAgentService::class)->storeMessage($session, 'user', 'Show revenue trend');
        $session->update(['status' => AnalyticsReportSessionStatus::Processing]);

        $job = new GenerateReportResponseJob(
            sessionId: $session->id,
            dashboardId: $dashboard->id,
            userId: $user->id,
            message: 'Show revenue trend',
            previewStart: '2025-06-01',
            previewEnd: '2025-06-30',
            clientMode: true,
        );

        $job->handle(app(ReportingAgentService::class));

        $this->assertSame(1, AnalyticsReportMessage::query()->where('role', 'user')->count());
        $this->assertSame(1, AnalyticsReportMessage::query()->where('role', 'assistant')->count());
    }

    public function test_agents_read_max_steps_from_config(): void
    {
        config([
            'titan.reporting.max_steps' => 12,
            'titan.reporting.client_max_steps' => 4,
        ]);

        $context = $this->context();

        $admin = ReportingAgent::make(context: $context);
        $client = ClientReportingAgent::make(context: $context);

        $this->assertSame(12, $admin->maxSteps());
        $this->assertSame(4, $client->maxSteps());
    }

    protected function context(?string $currentMessage = null): ReportingAgentContext
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $user = User::factory()->create();
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
            currentUserMessage: $currentMessage,
        );
    }

    protected function seededContext(): ReportingAgentContext
    {
        $context = $this->context();

        $connection = Connection::query()->create([
            'client_dashboard_id' => $context->dashboard->id,
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

        return $context;
    }
}
