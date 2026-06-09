<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ImpersonationService
{
    public const SESSION_KEY = 'titan.impersonator_id';

    public function start(User $admin, User $client): void
    {
        abort_unless($admin->isAdmin(), 403);
        abort_unless($client->isClient(), 403);

        Session::put(self::SESSION_KEY, $admin->id);
        Auth::login($client);
    }

    public function stop(): void
    {
        $adminId = Session::pull(self::SESSION_KEY);

        if ($adminId) {
            $admin = User::query()->find($adminId);

            if ($admin?->isAdmin()) {
                Auth::login($admin);
            }
        }
    }

    public function isImpersonating(): bool
    {
        return Session::has(self::SESSION_KEY);
    }

    public function impersonator(): ?User
    {
        $adminId = Session::get(self::SESSION_KEY);

        if (! $adminId) {
            return null;
        }

        $admin = User::query()->find($adminId);

        return $admin?->isAdmin() ? $admin : null;
    }
}
