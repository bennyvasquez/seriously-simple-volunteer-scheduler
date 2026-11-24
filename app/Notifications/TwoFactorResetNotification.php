<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the person whose two-factor an admin reset, so they hear about it even if they didn't ask. Names the admin by first name and last initial. */
class TwoFactorResetNotification extends Notification
{
    public string $adminFirstName;

    public function __construct(User $admin)
    {
        $this->adminFirstName = $admin->shortName();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::query()->value('name') ?? config('app.name');

        return (new MailMessage)
            ->subject(EmailText::line('two_factor_reset.subject'))
            ->line(EmailText::line('two_factor_reset.body', ['admin' => $this->adminFirstName, 'organization' => $organization]));
    }
}
