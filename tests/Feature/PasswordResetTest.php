<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_password_reset_email_from_users_index(): void
    {
        Notification::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $user = User::factory()->create(['email' => 'client@example.com', 'role' => UserRole::Client]);

        $this->actingAs($admin)
            ->from(route('admin.users.index'))
            ->post(route('admin.users.password-reset.store', $user))
            ->assertRedirect(route('admin.users.index'))
            ->assertSessionHas('status', 'Password reset email sent to client@example.com.');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_guest_can_request_password_reset_link(): void
    {
        Notification::fake();

        $user = User::factory()->create(['email' => 'client@example.com']);

        $this->from(route('password.request'))
            ->post(route('password.email'), ['email' => 'client@example.com'])
            ->assertRedirect(route('password.request'))
            ->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_user_can_reset_password_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'client@example.com',
            'password' => Hash::make('old-password'),
        ]);

        $token = Password::createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => 'client@example.com',
            'password' => 'new-secure-password',
            'password_confirmation' => 'new-secure-password',
        ])
            ->assertRedirect(route('login'))
            ->assertSessionHas('status', 'Your password has been reset. You can sign in now.');

        $this->assertTrue(Hash::check('new-secure-password', $user->fresh()->password));
    }
}
