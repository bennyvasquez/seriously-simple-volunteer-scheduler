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
            ->subject(EmailText::line('put_on_shift.subject', ['area' => $this->area]))
            ->line(EmailText::line('put_on_shift.body', ['area' => $this->area, 'when' => $this->when]));
    }
}
