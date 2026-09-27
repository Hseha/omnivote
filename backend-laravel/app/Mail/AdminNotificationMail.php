<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plain text admin alert for platform events (registration, vote, results,
 * admin actions). Sent through the app mailer to the panel roles when the
 * matching notification setting is enabled.
 */
class AdminNotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $type,
        public string $title,
        public ?string $body,
        public ?string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "[OmniVote] {$this->title}");
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-notification');
    }
}
