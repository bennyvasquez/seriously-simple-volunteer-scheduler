<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the person who released a shift who took it (wireframe 46). */
class ShiftCoveredNotification extends Notification
{
    public string $area;

    public string $date;

    public function __construct(Shift $shift, public string $takerFirstName)
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->takerFirstName.' is covering your '.$this->date.' shift')
            ->greeting('Hi '.$notifiable->firstName().',')
            ->line($this->takerFirstName.' took your '.$this->area.' shift on '.$this->date.'. You don\'t need to do anything.');
    }
}
