<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Auth\UserPasswordResetService;
use Illuminate\Http\RedirectResponse;

class UserPasswordResetController extends Controller
{
    public function store(User $user, UserPasswordResetService $service): RedirectResponse
    {
        try {
            $service->sendResetLink($user);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('status', 'Password reset email sent to '.$user->email.'.');
    }
}
