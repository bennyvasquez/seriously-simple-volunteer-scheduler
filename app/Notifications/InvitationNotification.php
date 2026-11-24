<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification
{
    public function __construct(public string $url, public ?string $invitedBy = null) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::query()->value('name') ?? config('app.name');

        return (new MailMessage)
            ->subject("You're invited to volunteer with {$organization}")
            ->greeting('Hi '.$notifiable->firstName().',')
            ->line($this->invitedBy === null
                ? "You've been added to {$organization}'s volunteer schedule."
                : "{$this->invitedBy} added you to {$organization}'s volunteer schedule.")
            ->action('Accept your invitation', $this->url)
            ->line('This link works once and expires in 7 days. If you weren\'t expecting this email, you can ignore it.');
    }
}
