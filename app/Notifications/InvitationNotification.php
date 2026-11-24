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
            ->subject(EmailText::line('invitation.subject', ['organization' => $organization]))
            ->greeting(EmailText::line('invitation.greeting', ['first_name' => $notifiable->firstName()]))
            ->line($this->invitedBy === null
                ? EmailText::line('invitation.intro', ['organization' => $organization])
                : EmailText::line('invitation.intro_by_person', ['invited_by' => $this->invitedBy, 'organization' => $organization]))
            ->action(EmailText::line('invitation.action'), $this->url)
            ->line(EmailText::line('invitation.outro'));
    }
}
