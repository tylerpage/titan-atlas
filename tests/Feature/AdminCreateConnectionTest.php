<?php

namespace Tests\Feature;

use App\Enums\ConnectorType;
use App\Enums\UserRole;
use App\Enums\SyncRunType;
use App\Jobs\Ingestion\SyncConnectionJob;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\Connection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class AdminCreateConnectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_access_add_connection_form(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($admin)
            ->get(route('admin.dashboards.connections.create', $dashboard))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Dashboards/Connections/Create')
                ->has('connectors', 7)
            );
    }

    public function test_admin_can_store_connection_and_queue_backfill(): void
    {
        Http::fake([
            'demo.myshopify.com/*' => Http::response([
                'shop' => ['name' => 'Demo Store'],
            ], 200),
        ]);

        Queue::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.dashboards.connections.store', $dashboard), [
                'name' => 'Shopify Store',
                'connector_type' => ConnectorType::Shopify->value,
                'credentials' => [
                    'shop_domain' => 'demo.myshopify.com',
                    'access_token' => 'demo-token',
                ],
            ])
            ->assertRedirect(route('admin.connections.show', Connection::query()->first()));

        $connection = Connection::query()->where('client_dashboard_id', $dashboard->id)->first();

        $this->assertNotNull($connection);
        $this->assertSame('Shopify Store', $connection->name);
        $this->assertSame(ConnectorType::Shopify, $connection->connector_type);

        Queue::assertPushed(SyncConnectionJob::class, function (SyncConnectionJob $job) use ($connection) {
            return $job->dashboardConnection->is($connection) && $job->type === SyncRunType::Backfill;
        });
    }

    public function test_client_cannot_add_connection(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($client)
            ->get(route('admin.dashboards.connections.create', $dashboard))
            ->assertForbidden();
    }
}
