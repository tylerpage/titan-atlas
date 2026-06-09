<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminEditDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_edit_dashboard_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.edit', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Edit')
                ->where('dashboard.name', 'Main')
            );
    }

    public function test_admin_can_update_dashboard(): void
    {
        Storage::fake('public');

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
            'primary_color' => '#1e40af',
            'secondary_color' => '#64748b',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dashboards.update', $dashboard), [
                'name' => 'Acme Performance',
                'slug' => 'performance',
                'timezone' => 'America/New_York',
                'default_date_range' => 'last_7_days',
                'attribution_window_days' => 14,
                'primary_color' => '#111827',
                'secondary_color' => '#6b7280',
                'custom_domain' => 'reports.acme.test',
                'logo' => UploadedFile::fake()->image('logo.png'),
            ])
            ->assertRedirect(route('admin.dashboards.show', $dashboard));

        $dashboard->refresh();

        $this->assertSame('Acme Performance', $dashboard->name);
        $this->assertSame('performance', $dashboard->slug);
        $this->assertSame('reports.acme.test', $dashboard->custom_domain);
        $this->assertNotNull($dashboard->logo_path);
        Storage::disk('public')->assertExists($dashboard->logo_path);
    }

    public function test_client_cannot_edit_dashboard(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($client)
            ->get(route('admin.dashboards.edit', $dashboard))
            ->assertForbidden();
    }
}
