<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateAdminUserCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_admin_user_from_options(): void
    {
        $this->artisan('titan:create-admin', [
            '--email' => 'admin@example.com',
            '--name' => 'Platform Admin',
            '--password' => 'secure-password',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Created admin user admin@example.com.');

        $user = User::query()->where('email', 'admin@example.com')->first();

        $this->assertNotNull($user);
        $this->assertSame('Platform Admin', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('secure-password', $user->password));
    }

    public function test_updates_existing_user_without_changing_password(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'name' => 'Old Name',
            'password' => 'old-password',
            'role' => UserRole::Client,
        ]);

        $this->artisan('titan:create-admin', [
            '--email' => 'admin@example.com',
            '--name' => 'New Name',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('password unchanged');

        $user->refresh();

        $this->assertSame('New Name', $user->name);
        $this->assertTrue($user->isAdmin());
        $this->assertTrue(Hash::check('old-password', $user->password));
    }

    public function test_updates_existing_user_password_when_provided(): void
    {
        $user = User::factory()->create([
            'email' => 'admin@example.com',
            'name' => 'Admin',
            'password' => 'old-password',
            'role' => UserRole::Admin,
        ]);

        $this->artisan('titan:create-admin', [
            '--email' => 'admin@example.com',
            '--name' => 'Admin',
            '--password' => 'new-password',
        ])
            ->assertSuccessful()
            ->expectsOutputToContain('Updated admin user admin@example.com.');

        $user->refresh();

        $this->assertTrue(Hash::check('new-password', $user->password));
    }

    public function test_fails_when_required_values_are_missing(): void
    {
        $this->artisan('titan:create-admin')
            ->assertFailed()
            ->expectsOutputToContain('Set --email or TITAN_ADMIN_EMAIL.');
    }
}
