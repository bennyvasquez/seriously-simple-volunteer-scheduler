<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the person whose two-factor an admin reset, so they hear about it even if they didn't ask. Names the admin by first name only. */
class TwoFactorResetNotification extends Notification
{
    public string $adminFirstName;

    public function __construct(User $admin)
    {
        $this->adminFirstName = $admin->firstName();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::query()->value('name') ?? config('app.name');

        return (new MailMessage)
            ->subject('Your second step was reset')
            ->line($this->adminFirstName.', an admin at '.$organization.', reset the second step for your login. Next time you log in with your password, you\'ll set up your authenticator app again. If you didn\'t ask for this, contact a coordinator right away.');
    }
}
