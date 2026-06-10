<?php

namespace App\Notifications;

use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Notifications\Messages\MailMessage;

class ResetPasswordNotification extends ResetPassword
{
    public function toMail($notifiable): MailMessage
    {
        $expireMinutes = config('auth.passwords.'.config('auth.defaults.passwords').'.expire', 60);

        return (new MailMessage)
            ->subject('Reset your '.config('app.name').' password')
            ->markdown('mail.reset-password', [
                'resetUrl' => $this->resetUrl($notifiable),
                'userName' => $notifiable->name,
                'expireMinutes' => $expireMinutes,
            ]);
    }
}
