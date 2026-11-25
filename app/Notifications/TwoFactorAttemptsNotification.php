<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the person (at most once a day) when a lot of wrong two-factor codes have been typed on their account. It goes to the person themselves, whatever their role. */
class TwoFactorAttemptsNotification extends Notification
{
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(EmailText::line('two_factor_attempts.subject'))
            ->greeting(EmailText::line('two_factor_attempts.greeting', ['first_name' => $notifiable->firstName()]))
            ->line(EmailText::line('two_factor_attempts.paused'))
            ->line(EmailText::line('two_factor_attempts.advice'))
            ->line(EmailText::line('two_factor_attempts.security', ['security_link' => EmailText::link(EmailText::line('two_factor_attempts.security_label'), route('account.security'))]));
    }
}
