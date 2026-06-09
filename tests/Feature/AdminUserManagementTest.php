<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminUserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_users(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['role' => UserRole::Client, 'name' => 'Acme Client']);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Index')
                ->has('users', 2)
            );
    }

    public function test_admin_can_create_user_with_company_and_dashboard_access(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($admin)
            ->post(route('admin.users.store'), [
                'name' => 'New Client',
                'email' => 'newclient@example.com',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role' => UserRole::Client->value,
                'company_ids' => [$company->id],
                'dashboard_ids' => [$dashboard->id],
            ])
            ->assertRedirect();

        $user = User::query()->where('email', 'newclient@example.com')->first();

        $this->assertNotNull($user);
        $this->assertTrue($user->belongsToCompany($company));
        $this->assertTrue($user->canAccessDashboard($dashboard));
    }

    public function test_admin_can_update_user(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['role' => UserRole::Client, 'name' => 'Before']);

        $this->actingAs($admin)
            ->post(route('admin.users.update', $user), [
                'name' => 'After',
                'email' => $user->email,
                'role' => UserRole::Client->value,
                'company_ids' => [],
                'dashboard_ids' => [],
            ])
            ->assertRedirect(route('admin.users.edit', $user));

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'After',
        ]);
    }

    public function test_admin_cannot_delete_self(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->from(route('admin.users.edit', $admin))
            ->delete(route('admin.users.destroy', $admin))
            ->assertRedirect(route('admin.users.edit', $admin))
            ->assertSessionHasErrors('user');

        $this->assertDatabaseHas('users', ['id' => $admin->id]);
    }

    public function test_client_cannot_manage_users(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $user = User::factory()->create(['role' => UserRole::Client]);

        $this->actingAs($client)
            ->get(route('admin.users.index'))
            ->assertForbidden();

        $this->actingAs($client)
            ->delete(route('admin.users.destroy', $user))
            ->assertForbidden();
    }
}
