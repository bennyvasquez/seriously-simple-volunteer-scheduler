<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the current address of someone without a password who wants to change their address. */
class ConfirmItsYouNotification extends Notification
{
    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Confirm it\'s you')
            ->line('You asked to change the email address you log in with. Open this link to confirm it\'s you and keep going.')
            ->action('Confirm it\'s me', $this->url)
            ->line('This link works once and expires in 15 minutes. If you didn\'t ask for it, you can ignore this email and nothing changes.');
    }
}
