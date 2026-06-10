<?php

namespace Tests\Unit;

use App\Agents\ReportingAgentContext;
use App\Enums\AnalyticsReportSessionStatus;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use App\Support\AgentAssistantTextResolver;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\ToolResult;
use Laravel\Ai\Responses\Data\Usage;
use Laravel\Ai\Responses\TextResponse;
use Tests\TestCase;

class AgentAssistantTextResolverTest extends TestCase
{
    use RefreshDatabase;
    public function test_uses_saved_report_when_agent_text_is_empty(): void
    {
        $context = $this->reportingContext();
        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $context->dashboard->id,
            'created_by' => $context->user->id,
            'prompt' => 'Revenue trend',
            'sql' => 'SELECT 1 AS revenue FROM connections WHERE client_dashboard_id = :dashboard_id',
            'visualization_type' => 'line_chart',
            'visualization_config' => ['title' => 'Revenue'],
            'model' => 'gpt-4o-mini',
        ]);
        $context->lastSavedReport = $report;

        $response = new TextResponse('', new Usage(0, 0), new Meta('openai', 'gpt-4o-mini'));

        $text = app(AgentAssistantTextResolver::class)->forReporting($response, $context);

        $this->assertStringContainsString('Revenue trend', $text);
        $this->assertStringNotContainsString('unable to generate', strtolower($text));
    }

    public function test_surfaces_tool_error_when_agent_text_is_empty(): void
    {
        $context = $this->reportingContext();
        $response = new TextResponse('', new Usage(0, 0), new Meta('openai', 'gpt-4o-mini'));
        $response->toolResults = collect([
            new ToolResult(
                id: 'call_1',
                name: 'preview_report_query',
                arguments: [],
                result: json_encode([
                    'success' => false,
                    'error' => 'Unknown column total_price',
                ]),
            ),
        ]);

        $text = app(AgentAssistantTextResolver::class)->forReporting($response, $context);

        $this->assertStringContainsString('Unknown column total_price', $text);
    }

    protected function reportingContext(): ReportingAgentContext
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
            previewStartDate: Carbon::parse('2025-06-01'),
            previewEndDate: Carbon::parse('2025-06-30'),
        );
    }
}
