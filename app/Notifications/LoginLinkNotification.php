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
            ->subject(EmailText::line('login_link.subject'))
            ->line(EmailText::line('login_link.intro'))
            ->action(EmailText::line('login_link.action'), $this->url)
            ->line(EmailText::line('login_link.outro'));
    }
}
