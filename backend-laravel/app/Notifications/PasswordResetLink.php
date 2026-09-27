<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The forgot-password email: a signed reset link that opens the admin SPA on
 * the Reset Password screen (?token= & ?email=). Also works with the default
 * `log` mailer — the rendered message (including the link) is written to
 * storage/logs/laravel.log until real SMTP credentials are configured.
 */
class PasswordResetLink extends Notification
{
    use Queueable;

    public function __construct(public string $token, public string $email)
    {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');

        return (new MailMessage)
            ->subject('Reset your OmniVote panel password')
            ->greeting('Hello,')
            ->line('You requested to reset your election panel password.')
            ->line('Click the button below to choose a new password. This link expires in 60 minutes.')
            ->action('Reset Password', $frontend.'/?token='.$this->token.'&email='.rawurlencode($this->email))
            ->line('If you did not request this, you can safely ignore this email — your password will not change.');
    }
}