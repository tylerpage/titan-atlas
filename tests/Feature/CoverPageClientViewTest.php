<?php

namespace Tests\Feature;

use App\Enums\CoverPageBlockType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use App\Models\DashboardShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverPageClientViewTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboardWithCoverPage(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $active = CoverPage::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'June 2025 Summary',
            'period_start' => '2025-06-01',
            'period_end' => '2025-06-30',
            'is_active' => true,
            'sort_order' => 1,
        ]);

        CoverPageBlock::query()->create([
            'cover_page_id' => $active->id,
            'block_type' => CoverPageBlockType::StatCard,
            'sort_order' => 1,
            'column_span' => 1,
            'configuration' => [
                'header' => 'Revenue',
                'text' => '$12,000',
                'tooltip' => 'Total revenue',
                'improvement_percent' => 8.5,
                'data_source' => 'manual',
            ],
        ]);

        $archived = CoverPage::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'May 2025 Summary',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        return [$dashboard, $client, $active, $archived];
    }

    public function test_client_dashboard_defaults_to_cover_tab_when_active_cover_exists(): void
    {
        [$dashboard, $client] = $this->createDashboardWithCoverPage();

        $this->actingAs($client)
            ->get(route('client.dashboard.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('tab', 'cover')
                ->where('hasCoverPages', true)
                ->has('coverPageData.blocks', 1)
                ->where('coverPageData.title', 'June 2025 Summary')
            );
    }

    public function test_client_can_view_archived_cover_page(): void
    {
        [$dashboard, $client, $active, $archived] = $this->createDashboardWithCoverPage();

        $this->actingAs($client)
            ->get(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'cover',
                'cover_page' => $archived->id,
            ]))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('selectedCoverPageId', $archived->id)
                ->where('coverPageData.title', 'May 2025 Summary')
            );
    }

    public function test_share_link_preserves_cover_tab_and_cover_page(): void
    {
        [$dashboard, $client, $active] = $this->createDashboardWithCoverPage();

        $response = $this->actingAs($client)
            ->postJson(route('client.dashboard.share', $dashboard), [
                'tab' => 'cover',
                'cover_page' => $active->id,
            ]);

        $response->assertOk();

        $link = DashboardShareLink::query()->first();

        $this->assertSame([
            'tab' => 'cover',
            'cover_page' => $active->id,
        ], $link->query);

        $this->get(route('dashboard.share.show', $link->code))
            ->assertRedirect(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'tab' => 'cover',
                'cover_page' => $active->id,
            ]));
    }
}
