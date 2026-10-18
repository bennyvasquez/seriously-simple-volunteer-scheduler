<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to someone a coordinator or lead put on a shift. */
class PutOnShiftNotification extends Notification
{
    public string $area;

    public string $when;

    public function __construct(Shift $shift)
    {
        $this->area = $shift->area->name;
        $this->when = $shift->whenText();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("You're on a {$this->area} shift")
            ->line("You're on the {$this->area} shift on {$this->when}. If you can't make it, release it from your shifts.");
    }
}
