<?php

namespace Modules\Foundation\Authentication\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use SensitiveParameter;

class OwnerActivationNotification extends Notification
{
    use Queueable;

    public function __construct(#[SensitiveParameter] private readonly string $token) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = rtrim((string) config('app.frontend_url', config('app.url')), '/').'/#/owner-activation?token='.$this->token;

        return (new MailMessage)
            ->subject('Continue your IVORQ owner activation')
            ->line('Use the secure owner activation page to continue. This link expires in 24 hours.')
            ->action('Continue activation', $url);
    }
}
