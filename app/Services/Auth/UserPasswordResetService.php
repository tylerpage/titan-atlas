<?php

namespace App\Services\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Validation\ValidationException;

class UserPasswordResetService
{
    public function sendResetLink(User $user): void
    {
        $status = Password::sendResetLink(['email' => $user->email]);

        if ($status === Password::RESET_LINK_SENT) {
            return;
        }

        if ($status === Password::RESET_THROTTLED) {
            throw ValidationException::withMessages([
                'user' => 'A reset email was sent recently. Please wait before sending another.',
            ]);
        }

        throw ValidationException::withMessages([
            'user' => __($status),
        ]);
    }
}
