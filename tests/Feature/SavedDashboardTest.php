<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\ReportVisualizationType;
use App\Enums\UserRole;
use App\Models\AnalyticsReport;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\RawConnectorPayload;
use App\Models\SavedDashboard;
use App\Models\SavedDashboardBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SavedDashboardTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboardWithClients(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $clientA = User::factory()->create(['role' => UserRole::Client, 'name' => 'Client A']);
        $clientB = User::factory()->create(['role' => UserRole::Client, 'name' => 'Client B']);
        $dashboard->users()->attach([$clientA->id, $clientB->id]);

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
            'payload' => [
                'date' => '2025-06-01',
                'total' => 500,
                'source_medium' => 'google / cpc',
            ],
            'payload_hash' => hash('sha256', '1001'),
            'fetched_at' => now(),
        ]);

        $report = AnalyticsReport::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'created_by' => $clientA->id,
            'prompt' => 'Total revenue',
            'sql' => 'SELECT SUM(CAST(json_extract(payload, \'$.total\') AS REAL)) AS revenue FROM raw_connector_payloads r JOIN connections c ON c.id = r.connection_id WHERE c.client_dashboard_id = :dashboard_id AND r.resource_type = \'order\' AND json_extract(r.payload, \'$.date\') BETWEEN :start_date AND :end_date',
            'visualization_type' => ReportVisualizationType::StatCard,
            'visualization_config' => [
                'header' => 'Revenue',
                'format' => 'currency',
                'value_column' => 'revenue',
            ],
        ]);

        return compact('dashboard', 'clientA', 'clientB', 'report');
    }

    public function test_client_can_create_and_view_shared_saved_dashboard(): void
    {
        ['dashboard' => $dashboard, 'clientA' => $clientA, 'report' => $report] = $this->createDashboardWithClients();

        $this->actingAs($clientA)
            ->post(route('client.dashboard.saved.store', $dashboard), [
                'title' => 'Q2 Performance',
                'description' => 'Key metrics for the quarter',
            ])
            ->assertRedirect();

        $board = SavedDashboard::query()->first();
        $this->assertNotNull($board);
        $this->assertSame('Q2 Performance', $board->title);

        $this->actingAs($clientA)
            ->post(route('client.dashboard.ai.reports.pin', [$dashboard, $report]), [
                'saved_dashboard_id' => $board->id,
                'title' => 'Revenue stat',
            ])
            ->assertRedirect(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'saved',
                'board' => $board->id,
            ]));

        $this->assertDatabaseHas('saved_dashboard_blocks', [
            'saved_dashboard_id' => $board->id,
            'analytics_report_id' => $report->id,
        ]);

        $redirect = $this->actingAs($clientA)
            ->get(route('client.dashboard.saved.show', [$dashboard, $board]).'?preview_start=2025-06-01&preview_end=2025-06-30');

        $redirect->assertRedirect();
        parse_str((string) parse_url((string) $redirect->headers->get('Location'), PHP_URL_QUERY), $query);
        $this->assertSame('saved', $query['tab'] ?? null);
        $this->assertSame((string) $board->id, $query['board'] ?? null);
        $this->assertSame('2025-06-01', $query['preview_start'] ?? null);
        $this->assertSame('2025-06-30', $query['preview_end'] ?? null);

        $this->actingAs($clientA)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'saved',
                'board' => $board->id,
                'preview_start' => '2025-06-01',
                'preview_end' => '2025-06-30',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('tab', 'saved')
                ->where('savedBoard.title', 'Q2 Performance')
                ->has('savedBoard.blocks', 1)
            );
    }

    public function test_second_client_can_see_shared_saved_dashboard(): void
    {
        ['dashboard' => $dashboard, 'clientA' => $clientA, 'clientB' => $clientB, 'report' => $report] = $this->createDashboardWithClients();

        $board = SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Shared board',
            'description' => 'Team view',
            'created_by' => $clientA->id,
        ]);

        SavedDashboardBlock::query()->create([
            'saved_dashboard_id' => $board->id,
            'analytics_report_id' => $report->id,
            'title' => 'Revenue',
            'column_span' => 1,
            'sort_order' => 1,
        ]);

        $this->actingAs($clientB)
            ->get(route('client.dashboard.saved.index', $dashboard))
            ->assertRedirect(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'saved',
            ]));

        $this->actingAs($clientB)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'saved',
                'preview_start' => '2025-06-01',
                'preview_end' => '2025-06-30',
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('tab', 'saved')
                ->where('savedBoard', null)
                ->has('savedBoards', 1)
                ->where('savedBoards.0.title', 'Shared board')
                ->has('savedBoards.0.blocks', 1)
            );
    }

    public function test_client_can_pin_report_from_chat_and_update_board(): void
    {
        ['dashboard' => $dashboard, 'clientA' => $clientA, 'report' => $report] = $this->createDashboardWithClients();

        $board = SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Original',
            'created_by' => $clientA->id,
        ]);

        $this->actingAs($clientA)
            ->post(route('client.dashboard.ai.reports.pin', [$dashboard, $report]), [
                'saved_dashboard_id' => $board->id,
                'title' => 'Pinned revenue',
            ])
            ->assertRedirect(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'saved',
                'board' => $board->id,
            ]));

        $this->assertDatabaseHas('saved_dashboard_blocks', [
            'saved_dashboard_id' => $board->id,
            'analytics_report_id' => $report->id,
        ]);

        $this->actingAs($clientA)
            ->patch(route('client.dashboard.saved.update', [$dashboard, $board]), [
                'title' => 'Updated title',
                'description' => 'Updated description',
            ])
            ->assertRedirect();

        $this->assertSame('Updated title', $board->fresh()->title);
    }

    public function test_client_can_unpin_visual(): void
    {
        ['dashboard' => $dashboard, 'clientA' => $clientA, 'report' => $report] = $this->createDashboardWithClients();

        $board = SavedDashboard::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'Board',
            'created_by' => $clientA->id,
        ]);

        $block = SavedDashboardBlock::query()->create([
            'saved_dashboard_id' => $board->id,
            'analytics_report_id' => $report->id,
            'sort_order' => 1,
        ]);

        $this->actingAs($clientA)
            ->delete(route('client.dashboard.saved.blocks.destroy', $block))
            ->assertRedirect();

        $this->assertDatabaseMissing('saved_dashboard_blocks', ['id' => $block->id]);
    }
}
