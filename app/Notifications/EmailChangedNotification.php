<?php

namespace App\Notifications;

use App\Models\Organization;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to the old address after a change, so the real owner hears about a change they didn't make. The new address is partly hidden. */
class EmailChangedNotification extends Notification
{
    public string $maskedAddress;

    public function __construct(string $newEmail)
    {
        $this->maskedAddress = self::mask($newEmail);
    }

    /** The first character, three bullets, then the @ and the domain: r•••@example.org. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');

        return mb_substr($email, 0, 1).'•••'.substr($email, $at);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $organization = Organization::query()->value('name') ?? config('app.name');

        return (new MailMessage)
            ->subject(EmailText::line('email_changed.subject'))
            ->line(EmailText::line('email_changed.body', ['organization' => $organization, 'new_address' => $this->maskedAddress]));
    }
}
