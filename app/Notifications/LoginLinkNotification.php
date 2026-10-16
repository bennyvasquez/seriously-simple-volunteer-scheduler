<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class LoginLinkNotification extends Notification
{
    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Your login link')
            ->line('Tap the button to log in.')
            ->action('Log in', $this->url)
            ->line('This link works once and expires in 15 minutes. If you didn\'t ask for it, you can ignore this email: nobody can log in without the link.');
    }
}
