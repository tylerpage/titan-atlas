<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\ClientDashboard;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class UserManagementService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): User
    {
        $user = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
            'role' => UserRole::from($data['role']),
        ]);

        $this->syncMemberships($user, $data['company_ids'] ?? [], $data['dashboard_ids'] ?? []);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(User $user, array $data): User
    {
        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => UserRole::from($data['role']),
        ]);

        if (! empty($data['password'])) {
            $user->update(['password' => Hash::make($data['password'])]);
        }

        $this->syncMemberships($user, $data['company_ids'] ?? [], $data['dashboard_ids'] ?? []);

        return $user->fresh();
    }

    public function delete(User $user, User $actor): void
    {
        if ($user->is($actor)) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete your own account.',
            ]);
        }

        if ($user->isAdmin() && User::query()->where('role', UserRole::Admin)->count() <= 1) {
            throw ValidationException::withMessages([
                'user' => 'You cannot delete the last admin account.',
            ]);
        }

        $user->companies()->detach();
        $user->clientDashboards()->detach();
        $user->delete();
    }

    /**
     * @param  list<int>  $companyIds
     * @param  list<int>  $dashboardIds
     */
    protected function syncMemberships(User $user, array $companyIds, array $dashboardIds): void
    {
        $user->companies()->sync($companyIds);

        $validDashboardIds = ClientDashboard::query()
            ->whereIn('id', $dashboardIds)
            ->when($companyIds !== [], fn ($query) => $query->whereIn('company_id', $companyIds))
            ->pluck('id')
            ->all();

        $user->clientDashboards()->sync($validDashboardIds);
    }
}
