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
            ->subject('Confirm your new email address')
            ->line('Someone asked to use this address to log in to '.$organization.'. If it was you, open this link to confirm. It works once, for 60 minutes. If it wasn\'t you, ignore this email and nothing changes.')
            ->action('Confirm this address', $this->url);
    }
}
