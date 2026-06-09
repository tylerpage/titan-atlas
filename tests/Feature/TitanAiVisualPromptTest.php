<?php

namespace Tests\Feature;

use App\Agents\ClientReportingPromptBuilder;
use App\Agents\ReportingAgentContext;
use App\Agents\TitanAiPromptSections;
use App\Enums\AnalyticsReportSessionStatus;
use App\Enums\ReportVisualizationType;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TitanAiVisualPromptTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_prompt_requires_save_analytics_report_for_data(): void
    {
        $prompt = app(ClientReportingPromptBuilder::class)->systemPrompt($this->context());

        $this->assertStringContainsString('NEVER format data as markdown tables', $prompt);
        $this->assertStringContainsString('SaveAnalyticsReportTool', $prompt);
        $this->assertStringContainsString(':start_date and :end_date', $prompt);
    }

    public function test_recent_session_reports_included_in_prompt(): void
    {
        $context = $this->context();

        AnalyticsReport::query()->create([
            'client_dashboard_id' => $context->dashboard->id,
            'analytics_report_session_id' => $context->session->id,
            'created_by' => $context->user->id,
            'prompt' => 'Top 5 sales days',
            'sql' => 'SELECT 1 AS revenue WHERE :dashboard_id = :dashboard_id AND :start_date = :start_date',
            'visualization_type' => ReportVisualizationType::Table,
            'visualization_config' => ['title' => 'Top days'],
        ]);

        $section = app(TitanAiPromptSections::class)->recentSessionReports($context);

        $this->assertStringContainsString('Top 5 sales days', $section);
        $this->assertStringContainsString('table', $section);
    }

    protected function context(): ReportingAgentContext
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
        );
    }
}
