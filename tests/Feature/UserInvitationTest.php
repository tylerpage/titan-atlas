<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\UserInvitationMail;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class UserInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_send_invitation_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.companies.show', $company))
            ->post(route('admin.companies.invitations.store', $company), [
                'email' => 'invitee@example.com',
                'role' => UserRole::Client->value,
                'dashboard_ids' => [$dashboard->id],
            ])
            ->assertRedirect(route('admin.companies.show', $company));

        $this->assertDatabaseHas('user_invitations', [
            'company_id' => $company->id,
            'email' => 'invitee@example.com',
            'role' => UserRole::Client->value,
        ]);

        Mail::assertSent(UserInvitationMail::class, fn (UserInvitationMail $mail) => $mail->hasTo('invitee@example.com'));
    }

    public function test_inviting_existing_user_adds_membership_without_email(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $existing = User::factory()->create(['email' => 'existing@example.com', 'role' => UserRole::Client]);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $this->actingAs($admin)
            ->from(route('admin.companies.show', $company))
            ->post(route('admin.companies.invitations.store', $company), [
                'email' => 'existing@example.com',
                'role' => UserRole::Client->value,
                'dashboard_ids' => [$dashboard->id],
            ])
            ->assertRedirect(route('admin.companies.show', $company));

        Mail::assertNothingSent();
        $this->assertTrue($existing->fresh()->belongsToCompany($company));
        $this->assertTrue($existing->fresh()->canAccessDashboard($dashboard));
    }

    public function test_guest_can_accept_invitation_and_log_in(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);
        $dashboard = ClientDashboard::query()->create([
            'company_id' => $company->id,
            'name' => 'Main',
            'slug' => 'main',
        ]);

        $invitation = UserInvitation::query()->create([
            'company_id' => $company->id,
            'email' => 'invitee@example.com',
            'role' => UserRole::Client->value,
            'token' => str_repeat('a', 64),
            'invited_by' => $admin->id,
            'dashboard_ids' => [$dashboard->id],
            'expires_at' => now()->addWeek(),
        ]);

        $this->get(route('invitations.show', $invitation->token))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Auth/AcceptInvitation')
                ->where('invitation.email', 'invitee@example.com')
            );

        $this->post(route('invitations.store', $invitation->token), [
            'name' => 'Invited User',
            'password' => 'password',
            'password_confirmation' => 'password',
        ])
            ->assertRedirect(route('home'));

        $user = User::query()->where('email', 'invitee@example.com')->first();

        $this->assertNotNull($user);
        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->belongsToCompany($company));
        $this->assertTrue($user->canAccessDashboard($dashboard));
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_admin_can_resend_invitation(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $invitation = UserInvitation::query()->create([
            'company_id' => $company->id,
            'email' => 'invitee@example.com',
            'role' => UserRole::Client->value,
            'token' => str_repeat('b', 64),
            'invited_by' => $admin->id,
            'dashboard_ids' => [],
            'expires_at' => now()->addDay(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.companies.invitations.resend', [$company, $invitation]))
            ->assertRedirect();

        Mail::assertSent(UserInvitationMail::class);
    }

    public function test_admin_can_resend_expired_invitation(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        $invitation = UserInvitation::query()->create([
            'company_id' => $company->id,
            'email' => 'invitee@example.com',
            'role' => UserRole::Client->value,
            'token' => str_repeat('c', 64),
            'invited_by' => $admin->id,
            'dashboard_ids' => [],
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->post(route('admin.companies.invitations.resend', [$company, $invitation]))
            ->assertRedirect();

        Mail::assertSent(UserInvitationMail::class);
        $this->assertTrue($invitation->fresh()->expires_at->isFuture());
    }

    public function test_users_index_lists_pending_invitations(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $company = Company::query()->create(['name' => 'Acme', 'slug' => 'acme']);

        UserInvitation::query()->create([
            'company_id' => $company->id,
            'email' => 'pending@example.com',
            'role' => UserRole::Client->value,
            'token' => str_repeat('d', 64),
            'invited_by' => $admin->id,
            'dashboard_ids' => [],
            'expires_at' => now()->subDay(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.users.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Admin/Users/Index')
                ->has('pendingInvitations', 1)
                ->has('companies', 1)
                ->has('roles')
                ->where('pendingInvitations.0.email', 'pending@example.com')
                ->where('pendingInvitations.0.is_expired', true)
            );
    }
}
