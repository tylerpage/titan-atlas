<?php

namespace App\Mail;

use App\Models\UserInvitation;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class UserInvitationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public UserInvitation $invitation,
        public string $acceptUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'You\'re invited to '.$this->invitation->company->name,
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.user-invitation',
            with: [
                'companyName' => $this->invitation->company->name,
                'inviterName' => $this->invitation->inviter->name,
                'acceptUrl' => $this->acceptUrl,
                'expiresAt' => $this->invitation->expires_at,
            ],
        );
    }
}
