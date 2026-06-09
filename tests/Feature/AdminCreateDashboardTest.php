<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\DashboardTemplate;
use App\Models\User;
use App\Models\WidgetPlacement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCreateDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_create_dashboard_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.create'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Create')
                ->has('companies', 1)
                ->has('templates')
            );
    }

    public function test_admin_can_store_new_dashboard(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Beta Corp', 'slug' => 'beta-corp']);
        $template = DashboardTemplate::query()->create([
            'name' => 'SEO Overview',
            'slug' => 'seo-overview',
            'default_widgets' => ['revenue', 'orders'],
            'is_active' => true,
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dashboards.store'), [
                'company_id' => $company->id,
                'name' => 'Beta Main',
                'slug' => 'beta-main',
                'dashboard_template_id' => $template->id,
                'timezone' => 'America/Chicago',
                'default_date_range' => 'last_30_days',
                'attribution_window_days' => 30,
            ])
            ->assertRedirect();

        $dashboard = ClientDashboard::query()->where('slug', 'beta-main')->first();

        $this->assertNotNull($dashboard);
        $this->assertSame('Beta Main', $dashboard->name);
        $this->assertSame($company->id, $dashboard->company_id);
        $this->assertSame($template->id, $dashboard->dashboard_template_id);
        $this->assertSame('Powered by Irish Titan', $dashboard->powered_by_text);
        $this->assertSame(2, WidgetPlacement::query()->where('client_dashboard_id', $dashboard->id)->count());
    }

    public function test_client_cannot_access_create_dashboard_form(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($client)
            ->get(route('admin.dashboards.create'))
            ->assertForbidden();
    }

    public function test_client_cannot_store_dashboard(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $company = Company::query()->create(['name' => 'Gamma', 'slug' => 'gamma']);

        $this->actingAs($client)
            ->post(route('admin.dashboards.store'), [
                'company_id' => $company->id,
                'name' => 'Blocked',
            ])
            ->assertForbidden();
    }
}
