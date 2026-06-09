<?php

namespace Tests\Feature;

use App\Ai\Agents\ReportingAgent;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\AnalyticsReportMessage;
use App\Models\AnalyticsReportSession;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use App\Enums\AnalyticsReportSessionStatus;
use App\Jobs\GenerateReportResponseJob;
use App\Services\AI\ReportingAgentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ReportingAgentTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboard(): ClientDashboard
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        return ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
    }

    public function test_admin_can_start_report_session_via_http(): void
    {
        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboard();

        $this->actingAs($admin)
            ->post(route('admin.dashboards.reports.sessions.store', $dashboard), [
                'message' => 'What was total revenue?',
                'preview_start' => '2025-06-01',
                'preview_end' => '2025-06-30',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('analytics_report_sessions', [
            'client_dashboard_id' => $dashboard->id,
            'user_id' => $admin->id,
            'status' => AnalyticsReportSessionStatus::Processing->value,
        ]);

        $this->assertDatabaseHas('analytics_report_messages', [
            'role' => 'user',
            'content' => 'What was total revenue?',
        ]);

        Queue::assertPushed(GenerateReportResponseJob::class);
    }

    public function test_reporting_agent_service_stores_messages(): void
    {
        ReportingAgent::fake(['Here is your revenue breakdown.']);

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboard();
        $service = app(ReportingAgentService::class);

        $result = $service->sendMessage($dashboard, $admin, 'Show revenue by day');

        $this->assertInstanceOf(AnalyticsReportSession::class, $result['session']);
        $this->assertSame('Here is your revenue breakdown.', $result['response']);
        $this->assertGreaterThanOrEqual(2, AnalyticsReportMessage::query()->count());
    }

    public function test_admin_can_view_reports_index(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboard();

        AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'created_by' => $admin->id,
            'prompt' => 'Revenue trend',
            'sql' => 'SELECT 1 AS revenue FROM connections WHERE client_dashboard_id = :dashboard_id',
            'visualization_type' => 'line_chart',
            'visualization_config' => ['title' => 'Revenue'],
            'model' => 'gpt-4o-mini',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.reports.index', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Reports/Index')
                ->has('reports', 1)
            );
    }
}
