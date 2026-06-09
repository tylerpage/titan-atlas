<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCompanyTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_list_companies(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($admin)
            ->get(route('admin.companies.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Companies/Index')
                ->has('companies', 1)
            );
    }

    public function test_admin_can_create_company(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)
            ->post(route('admin.companies.store'), [
                'name' => 'Keller-Heartt',
                'slug' => 'keller-heartt',
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('companies', [
            'name' => 'Keller-Heartt',
            'slug' => 'keller-heartt',
        ]);
    }

    public function test_admin_can_update_company(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($admin)
            ->post(route('admin.companies.update', $company), [
                'name' => 'Acme Corp',
                'slug' => 'acme-corp',
            ])
            ->assertRedirect(route('admin.companies.show', $company));

        $this->assertDatabaseHas('companies', [
            'id' => $company->id,
            'name' => 'Acme Corp',
            'slug' => 'acme-corp',
        ]);
    }

    public function test_admin_can_view_company(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($admin)
            ->get(route('admin.companies.show', $company))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Companies/Show')
                ->where('company.name', 'Acme')
            );
    }

    public function test_admin_can_delete_company_without_dashboards(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($admin)
            ->delete(route('admin.companies.destroy', $company))
            ->assertRedirect(route('admin.companies.index'));

        $this->assertDatabaseMissing('companies', ['id' => $company->id]);
    }

    public function test_admin_cannot_delete_company_with_dashboards(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.companies.show', $company))
            ->delete(route('admin.companies.destroy', $company))
            ->assertRedirect(route('admin.companies.show', $company))
            ->assertSessionHasErrors('company');

        $this->assertDatabaseHas('companies', ['id' => $company->id]);
    }

    public function test_client_cannot_manage_companies(): void
    {
        $client = User::factory()->create(['role' => UserRole::Client]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $this->actingAs($client)
            ->get(route('admin.companies.index'))
            ->assertForbidden();

        $this->actingAs($client)
            ->post(route('admin.companies.store'), ['name' => 'Blocked'])
            ->assertForbidden();

        $this->actingAs($client)
            ->delete(route('admin.companies.destroy', $company))
            ->assertForbidden();
    }
}
