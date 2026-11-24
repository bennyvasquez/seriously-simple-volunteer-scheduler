<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class PasswordChangedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(EmailText::line('password_changed.subject'))
            ->line(EmailText::line('password_changed.intro'))
            ->line(EmailText::line('password_changed.outro'));
    }
}
