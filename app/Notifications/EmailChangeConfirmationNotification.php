<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to a new address someone wants to log in with. The change happens only when this link is opened. */
class EmailChangeConfirmationNotification extends Notification
{
    public function __construct(public string $url) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::query()->value('name') ?? config('app.name');

        return (new MailMessage)
            ->subject(EmailText::line('email_change_confirmation.subject'))
            ->line(EmailText::line('email_change_confirmation.intro', ['organization' => $organization]))
            ->action(EmailText::line('email_change_confirmation.action'), $this->url);
    }
}
