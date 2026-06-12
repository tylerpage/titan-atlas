<?php

namespace Tests\Feature;

use App\Agents\ClientReportingPromptBuilder;
use App\Agents\ReportingAgentContext;
use App\Ai\Tools\AnalyzeCampaignPerformanceTool;
use App\Enums\ConnectorType;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\User;
use App\Services\AI\DashboardAgentMemoryService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;
use Tests\TestCase;

class PaidMediaAgentTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_prompt_includes_paid_media_skill_for_budget_questions(): void
    {
        $prompt = app(ClientReportingPromptBuilder::class)->systemPrompt($this->context(
            'Which campaigns should we re-allocate budget from?',
        ));

        $this->assertStringContainsString('Skill: paid media', $prompt);
        $this->assertStringContainsString('AnalyzeCampaignPerformanceTool', $prompt);
        $this->assertStringContainsString('campaign_daily', $prompt);
    }

    public function test_analyze_campaign_performance_tool_returns_campaign_rows(): void
    {
        [$dashboard, $connection, $user] = $this->seedGoogleAdsCampaign();

        $tool = app(AnalyzeCampaignPerformanceTool::class, [
            'context' => $this->context('Show campaign performance', $dashboard, $user, $connection),
        ]);

        $result = json_decode((string) $tool->handle(new Request([])), true);

        $this->assertTrue($result['success']);
        $this->assertSame('google_ads', $result['connector_type']);
        $this->assertCount(1, $result['campaigns']);
        $this->assertSame('Brand', $result['campaigns'][0]['campaign_name']);
        $this->assertSame(2.5, $result['campaigns'][0]['roas']);
    }

    public function test_successful_analysis_saves_dashboard_memory(): void
    {
        [$dashboard, $connection, $user] = $this->seedGoogleAdsCampaign();

        $tool = app(AnalyzeCampaignPerformanceTool::class, [
            'context' => $this->context('Budget reallocation', $dashboard, $user, $connection),
        ]);
        $tool->handle(new Request([]));

        $memory = app(DashboardAgentMemoryService::class)->listForDashboard($dashboard, 'reporting');

        $this->assertNotEmpty($memory);
        $this->assertSame('google_ads:campaign_snapshot', $memory[0]['memory_key']);
    }

    protected function seedGoogleAdsCampaign(): array
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme-paid'])->id,
            'name' => 'Main',
            'slug' => 'main-paid',
        ]);

        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Google Ads',
            'connector_type' => ConnectorType::GoogleAds,
            'encrypted_credentials' => ['customer_id' => '123', 'refresh_token' => 'token'],
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'campaign_daily',
            'external_id' => '2025-06-01:111',
            'payload' => [
                'date' => '2025-06-01',
                'campaign_id' => '111',
                'campaign_name' => 'Brand',
                'cost' => 100,
                'impressions' => 1000,
                'clicks' => 50,
                'ctr' => 0.05,
                'conversions_value' => 250,
            ],
            'payload_hash' => hash('sha256', 'campaign-paid'),
            'fetched_at' => now(),
        ]);

        $user = User::factory()->create();

        return [$dashboard, $connection, $user];
    }

    protected function context(
        string $message = 'test',
        ?ClientDashboard $dashboard = null,
        ?User $user = null,
        ?Connection $connection = null,
    ): ReportingAgentContext {
        [$dashboard, $connection, $user] = $dashboard === null
            ? $this->seedGoogleAdsCampaign()
            : [$dashboard, $connection, $user];

        $session = \App\Models\AnalyticsReportSession::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $user->id,
            'status' => \App\Enums\AnalyticsReportSessionStatus::Active,
            'title' => 'Test',
        ]);

        return new ReportingAgentContext(
            session: $session,
            dashboard: $dashboard,
            user: $user,
            previewStartDate: Carbon::parse('2025-06-01'),
            previewEndDate: Carbon::parse('2025-06-01'),
            currentUserMessage: $message,
            connectionId: $connection?->id,
        );
    }
}
