<?php

namespace App\Mail;

use App\Models\FeedbackSubmission;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class FeedbackCompletedMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public FeedbackSubmission $submission,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Your feedback is complete — '.config('app.name'),
        );
    }

    public function content(): Content
    {
        $this->submission->loadMissing('user');

        return new Content(
            markdown: 'mail.feedback-completed',
            with: [
                'userName' => $this->submission->user->name,
                'reasonLabel' => $this->submission->reason->label(),
                'feedbackMessage' => $this->submission->message,
                'completionMessage' => $this->submission->completion_message,
                'submittedAt' => $this->submission->created_at,
            ],
        );
    }
}
