<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\DashboardShareLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardShareTest extends TestCase
{
    use RefreshDatabase;

    protected function createDashboardWithClient(): array
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $client = User::factory()->create(['role' => UserRole::Client]);
        $dashboard->users()->attach($client);

        return [$dashboard, $client];
    }

    public function test_user_can_create_share_link_for_current_filters(): void
    {
        [$dashboard, $client] = $this->createDashboardWithClient();

        $response = $this->actingAs($client)
            ->postJson(route('client.dashboard.share', $dashboard), [
                'range' => 'last_90_days',
                'compare' => 'previous_period',
                'connection' => 4,
            ]);

        $response->assertOk()
            ->assertJsonStructure(['url', 'code']);

        $link = DashboardShareLink::query()->first();

        $this->assertNotNull($link);
        $this->assertSame($dashboard->id, $link->client_dashboard_id);
        $this->assertSame($client->id, $link->created_by_user_id);
        $this->assertSame([
            'range' => 'last_90_days',
            'compare' => 'previous_period',
            'connection' => 4,
        ], $link->query);
        $this->assertStringContainsString('/s/'.$link->code, $response->json('url'));
    }

    public function test_short_link_redirects_to_dashboard_with_query_params(): void
    {
        [$dashboard, $client] = $this->createDashboardWithClient();

        $link = DashboardShareLink::query()->create([
            'code' => 'abc12345',
            'client_dashboard_id' => $dashboard->id,
            'created_by_user_id' => $client->id,
            'query' => [
                'range' => 'custom',
                'start' => '2024-01-01',
                'end' => '2024-01-31',
                'connection' => 2,
            ],
        ]);

        $this->get(route('dashboard.share.show', $link->code))
            ->assertRedirect(route('client.dashboard.show', [
                'dashboard' => $dashboard,
                'range' => 'custom',
                'start' => '2024-01-01',
                'end' => '2024-01-31',
                'connection' => 2,
            ]));
    }

    public function test_user_without_access_cannot_create_share_link(): void
    {
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);
        $other = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($other)
            ->postJson(route('client.dashboard.share', $dashboard), [
                'range' => 'last_30_days',
            ])
            ->assertForbidden();
    }
}
