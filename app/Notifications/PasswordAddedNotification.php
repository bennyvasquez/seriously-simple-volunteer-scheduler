<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the person after a first password is added to their account, so a password they didn't add gets noticed. */
class PasswordAddedNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(EmailText::line('password_added.subject'))
            ->greeting(EmailText::line('password_added.greeting', ['first_name' => $notifiable->firstName()]))
            ->line(EmailText::line('password_added.added'))
            ->line(EmailText::line('password_added.advice'))
            ->line(EmailText::line('password_added.reset', ['reset_link' => EmailText::link(EmailText::line('password_added.reset_label'), route('password.request'))]));
    }
}
