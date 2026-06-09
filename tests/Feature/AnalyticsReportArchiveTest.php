<?php

namespace Tests\Feature;

use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsReportArchiveTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_archive_and_restore_report(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboard();
        $report = $this->createReport($dashboard);

        $this->actingAs($admin)
            ->post(route('admin.reports.archive', $report))
            ->assertRedirect(route('admin.dashboards.reports.index', $dashboard));

        $report->refresh();
        $this->assertNotNull($report->archived_at);
        $this->assertDatabaseHas('analytics_reports', ['id' => $report->id]);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.reports.index', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('reports', 0)
                ->has('archivedReports', 1)
                ->where('archivedReports.0.id', $report->id)
            );

        $this->actingAs($admin)
            ->post(route('admin.reports.restore', $report))
            ->assertRedirect(route('admin.dashboards.reports.index', $dashboard));

        $this->assertNull($report->fresh()->archived_at);
    }

    public function test_archived_report_is_excluded_from_cover_page_picker(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboard();
        $active = $this->createReport($dashboard, prompt: 'Active report');
        $archived = $this->createReport($dashboard, prompt: 'Archived report');
        $archived->archive();

        $coverPage = \App\Models\CoverPage::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Summary',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        $this->actingAs($admin)
            ->get(route('admin.cover-pages.edit', $coverPage))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('savedReports', 1)
                ->where('savedReports.0.id', $active->id)
            );
    }

    protected function createDashboard(): ClientDashboard
    {
        return ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
    }

    protected function createReport(ClientDashboard $dashboard, string $prompt = 'Revenue trend'): AnalyticsReport
    {
        return AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'created_by' => User::factory()->create()->id,
            'prompt' => $prompt,
            'sql' => 'SELECT 1 AS value',
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [],
        ]);
    }
}
