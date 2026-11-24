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
            ->subject(EmailText::line('confirm_its_you.subject'))
            ->line(EmailText::line('confirm_its_you.intro'))
            ->action(EmailText::line('confirm_its_you.action'), $this->url)
            ->line(EmailText::line('confirm_its_you.outro'));
    }
}
