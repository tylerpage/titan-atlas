<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClientDashboardSlugRouteTest extends TestCase
{
    use RefreshDatabase;

    public function test_client_dashboard_uses_slug_url(): void
    {
        $dashboard = $this->createDashboard();
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $this->actingAs($client)
            ->get('/'.$dashboard->slug)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Client/Dashboard')
                ->where('dashboard.slug', $dashboard->slug)
            );
    }

    public function test_legacy_dashboard_id_url_redirects_to_slug_url(): void
    {
        $dashboard = $this->createDashboard();
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        $this->actingAs($client)
            ->get('/dashboards/'.$dashboard->id.'?tab=data')
            ->assertRedirect('/'.$dashboard->slug.'?tab=data');
    }

    public function test_route_helper_generates_slug_url(): void
    {
        $dashboard = $this->createDashboard();

        $this->assertSame(
            url('/'.$dashboard->slug),
            route('client.dashboard.show', $dashboard),
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
}
