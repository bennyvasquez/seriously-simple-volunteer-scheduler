<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TwoFactorCodeNotification extends Notification
{
    public function __construct(public string $code) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(EmailText::line('two_factor_code.subject'))
            ->line(EmailText::line('two_factor_code.code', ['code' => $this->code]))
            ->line(EmailText::line('two_factor_code.outro'));
    }
}
