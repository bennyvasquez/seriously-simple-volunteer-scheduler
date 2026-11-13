<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to everyone still on a shift that a coordinator brought back after cancelling it. */
class ShiftRestoredNotification extends Notification
{
    public string $area;

    public string $whenText;

    public function __construct(Shift $shift)
    {
        $this->area = $shift->area->name;
        $this->whenText = $shift->whenText();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("Your {$this->area} shift is back on")
            ->line("The {$this->area} shift on {$this->whenText} is back on, and you're still on it. If you can't make it, let your coordinator know.");
    }
}
