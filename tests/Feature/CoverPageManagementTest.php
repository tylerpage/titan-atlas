<?php

namespace Tests\Feature;

use App\Enums\CoverPageBlockType;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\CoverPage;
use App\Models\CoverPageBlock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CoverPageManagementTest extends TestCase
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

    public function test_admin_can_create_and_activate_cover_page(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboard();

        $this->actingAs($admin)
            ->post(route('admin.dashboards.cover-pages.store', $dashboard), [
                'title' => 'June 2025 Summary',
                'period_start' => '2025-06-01',
                'period_end' => '2025-06-30',
                'is_active' => true,
            ])
            ->assertRedirect();

        $coverPage = CoverPage::query()->first();

        $this->assertNotNull($coverPage);
        $this->assertTrue($coverPage->is_active);
        $this->assertSame('June 2025 Summary', $coverPage->title);

        $second = CoverPage::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'title' => 'May 2025 Summary',
            'is_active' => false,
            'sort_order' => 2,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.cover-pages.activate', $second))
            ->assertRedirect();

        $this->assertFalse($coverPage->fresh()->is_active);
        $this->assertTrue($second->fresh()->is_active);
    }

    public function test_client_cannot_manage_cover_pages(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard = $this->createDashboard();
        $dashboard->users()->attach($client);

        $this->actingAs($client)
            ->get(route('admin.dashboards.cover-pages.index', $dashboard))
            ->assertForbidden();
    }
}
