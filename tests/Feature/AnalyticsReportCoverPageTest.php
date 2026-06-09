<?php

namespace Tests\Feature;

use App\Enums\CoverPageBlockType;
use App\Enums\CoverPageDataSource;
use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use App\Models\RawConnectorPayload;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AnalyticsReportCoverPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setupDashboardWithReport(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $connection = Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Store',
            'connector_type' => 'bigcommerce',
            'encrypted_credentials' => encrypt('{}'),
        ]);

        RawConnectorPayload::query()->create([
            'connection_id' => $connection->id,
            'resource_type' => 'order',
            'external_id' => '1',
            'payload' => ['date' => '2025-06-10', 'total' => 200, 'order_number' => '#1'],
            'fetched_at' => now(),
        ]);

        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'created_by' => $admin->id,
            'prompt' => 'Total revenue',
            'sql' => <<<'SQL'
SELECT COALESCE(SUM(CAST(json_extract(r.payload, '$.total') AS REAL)), 0) AS revenue
FROM raw_connector_payloads r
JOIN connections c ON c.id = r.connection_id
WHERE c.client_dashboard_id = :dashboard_id
  AND r.resource_type = 'order'
  AND json_extract(r.payload, '$.date') BETWEEN :start_date AND :end_date
SQL,
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [
                'header' => 'Revenue',
                'value_column' => 'revenue',
                'format' => 'currency',
            ],
            'model' => 'gpt-4o-mini',
        ]);

        $coverPage = CoverPage::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'June Summary',
            'period_start' => '2025-06-01',
            'period_end' => '2025-06-30',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        CoverPageBlock::query()->create([
            'cover_page_id' => $coverPage->id,
            'block_type' => CoverPageBlockType::StatCard,
            'sort_order' => 1,
            'column_span' => 1,
            'configuration' => [
                'data_source' => CoverPageDataSource::Report->value,
                'report_id' => $report->id,
                'header' => 'Revenue',
            ],
        ]);

        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        return [$dashboard, $coverPage, $client, $report];
    }

    public function test_report_block_resolves_on_client_summary_tab(): void
    {
        [$dashboard, $coverPage, $client] = $this->setupDashboardWithReport();

        $response = $this->actingAs($client)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard->id,
                'tab' => 'cover',
                'cover_page' => $coverPage->id,
            ]));

        $response->assertOk();
        $response->assertInertia(fn ($page) => $page
            ->component('Client/Dashboard')
            ->has('coverPageData.blocks', 1)
            ->where('coverPageData.blocks.0.type', 'stat_card')
            ->where('coverPageData.blocks.0.header', 'Revenue')
            ->where('coverPageData.blocks.0.text', '$200.00')
            ->where('coverPageData.blocks.0.ai_report.prompt', 'Total revenue')
        );
    }

    public function test_admin_can_place_report_on_cover_page(): void
    {
        [$dashboard, $coverPage, , $report] = $this->setupDashboardWithReport();
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.dashboards.reports.place', [$dashboard->id, $report->id]), [
                'cover_page_id' => $coverPage->id,
                'column_span' => 1,
            ])
            ->assertRedirect(route('admin.cover-pages.edit', $coverPage));

        $this->assertSame(2, CoverPageBlock::query()->where('cover_page_id', $coverPage->id)->count());
    }

    public function test_client_cannot_access_report_admin_routes(): void
    {
        [$dashboard, , $client] = $this->setupDashboardWithReport();

        $this->actingAs($client)
            ->get(route('admin.dashboards.reports.index', $dashboard))
            ->assertForbidden();
    }
}
