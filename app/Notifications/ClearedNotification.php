<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to someone a coordinator cleared for regular shifts from the person page. Names the coordinator by first name and last initial. The coordinator's note stays in the change log and isn't emailed. */
class ClearedNotification extends Notification
{
    public string $coordinatorFirstName;

    public string $organization;

    public function __construct(User $coordinator, Organization $organization)
    {
        $this->coordinatorFirstName = $coordinator->shortName();
        $this->organization = $organization->name;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(EmailText::line('cleared.subject'))
            ->greeting(EmailText::line('cleared.greeting', ['first_name' => $notifiable->firstName()]))
            ->line(EmailText::line('cleared.body', ['coordinator' => $this->coordinatorFirstName, 'organization' => $this->organization]));
    }
}
