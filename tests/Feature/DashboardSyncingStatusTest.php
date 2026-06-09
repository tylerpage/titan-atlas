<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\SyncStatus;
use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardSyncingStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_show_reports_when_a_connector_is_syncing(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboardWithConnection(SyncStatus::Running);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Show')
                ->where('dashboard.is_syncing', true)
            );
    }

    public function test_client_dashboard_reports_when_an_active_connector_is_syncing(): void
    {
        $dashboard = $this->createDashboardWithConnection(SyncStatus::Running);
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $this->actingAs($client)
            ->get(route('client.dashboard.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('dashboard.is_syncing', true)
            );
    }

    public function test_dashboard_is_not_syncing_when_all_connectors_are_idle(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $dashboard = $this->createDashboardWithConnection(SyncStatus::Success);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.show', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('dashboard.is_syncing', false));
    }

    protected function createDashboardWithConnection(SyncStatus $syncStatus): ClientDashboard
    {
        $dashboard = ClientDashboard::query()->create([
            'company_id' => Company::query()->create(['name' => 'Acme', 'slug' => 'acme'])->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        Connection::query()->create([
            'client_dashboard_id' => $dashboard->id,
            'name' => 'Store',
            'connector_type' => ConnectorType::Shopify,
            'encrypted_credentials' => ['shop_domain' => 'demo.myshopify.com', 'access_token' => 'token'],
            'sync_status' => $syncStatus,
            'is_active' => true,
        ]);

        return $dashboard;
    }
}
