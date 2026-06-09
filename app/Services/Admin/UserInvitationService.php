<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Mail\UserInvitationMail;
use App\Models\ClientDashboard;
use App\Models\Company;
use App\Models\User;
use App\Models\UserInvitation;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UserInvitationService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function invite(Company $company, User $inviter, array $data): ?UserInvitation
    {
        $email = strtolower(trim($data['email']));
        $role = UserRole::from($data['role']);
        $dashboardIds = $this->validDashboardIds($company, $data['dashboard_ids'] ?? []);

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser) {
            $this->attachExistingUser($existingUser, $company, $dashboardIds, $role);

            return null;
        }

        $this->revokePendingInvitations($company, $email);

        $invitation = UserInvitation::query()->create([
            'company_id' => $company->id,
            'email' => $email,
            'role' => $role->value,
            'token' => Str::random(64),
            'invited_by' => $inviter->id,
            'dashboard_ids' => $dashboardIds,
            'expires_at' => now()->addDays((int) config('titan.invitations.expires_days', 7)),
        ]);

        $invitation->load(['company', 'inviter']);

        Mail::to($email)->send(new UserInvitationMail(
            $invitation,
            route('invitations.show', $invitation->token),
        ));

        return $invitation;
    }

    public function resend(UserInvitation $invitation): UserInvitation
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation is no longer pending.',
            ]);
        }

        $invitation->update([
            'expires_at' => now()->addDays((int) config('titan.invitations.expires_days', 7)),
        ]);

        $invitation->load(['company', 'inviter']);

        Mail::to($invitation->email)->send(new UserInvitationMail(
            $invitation,
            route('invitations.show', $invitation->token),
        ));

        return $invitation->fresh();
    }

    public function revoke(UserInvitation $invitation): void
    {
        if ($invitation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'invitation' => 'Accepted invitations cannot be revoked.',
            ]);
        }

        $invitation->delete();
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function accept(UserInvitation $invitation, array $data): User
    {
        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => 'This invitation is no longer valid.',
            ]);
        }

        $user = User::query()->where('email', $invitation->email)->first();

        if ($user) {
            $this->attachExistingUser(
                $user,
                $invitation->company,
                $invitation->dashboard_ids ?? [],
                UserRole::from($invitation->role),
            );
        } else {
            $user = User::query()->create([
                'name' => $data['name'],
                'email' => $invitation->email,
                'password' => Hash::make($data['password']),
                'role' => UserRole::from($invitation->role),
                'email_verified_at' => now(),
            ]);

            $user->companies()->syncWithoutDetaching([$invitation->company_id]);

            if ($invitation->dashboard_ids) {
                $user->clientDashboards()->syncWithoutDetaching($invitation->dashboard_ids);
            }
        }

        $invitation->update(['accepted_at' => now()]);

        return $user;
    }

    /**
     * @param  list<int>  $dashboardIds
     */
    protected function attachExistingUser(User $user, Company $company, array $dashboardIds, UserRole $role): void
    {
        if ($user->role !== $role && ! $user->isAdmin()) {
            $user->update(['role' => $role]);
        }

        $user->companies()->syncWithoutDetaching([$company->id]);

        if ($dashboardIds !== []) {
            $user->clientDashboards()->syncWithoutDetaching($dashboardIds);
        }
    }

    /**
     * @param  list<int>  $dashboardIds
     * @return list<int>
     */
    protected function validDashboardIds(Company $company, array $dashboardIds): array
    {
        if ($dashboardIds === []) {
            return [];
        }

        return ClientDashboard::query()
            ->where('company_id', $company->id)
            ->whereIn('id', $dashboardIds)
            ->pluck('id')
            ->all();
    }

    protected function revokePendingInvitations(Company $company, string $email): void
    {
        UserInvitation::query()
            ->where('company_id', $company->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->delete();
    }
}
