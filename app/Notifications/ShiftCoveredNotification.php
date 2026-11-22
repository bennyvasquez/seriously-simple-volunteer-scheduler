<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Tells the person who released a shift who took it (wireframe 46). When someone takes a slot and covers several of their dates at once, merge() folds them into one email listing the dates. */
class ShiftCoveredNotification extends Notification
{
    public string $area;

    public string $date;

    /** @var list<string> "Thursday, October 22" for each shift, the first one included. */
    public array $dates;

    public function __construct(Shift $shift, public string $takerFirstName)
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
        $this->dates = [$this->date];
    }

    /** Adds another covered shift to this email, so the releaser gets one listing both. */
    public function merge(self $other): self
    {
        $this->dates = array_merge($this->dates, $other->dates);

        return $this;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (count($this->dates) > 1) {
            $mail = (new MailMessage)
                ->subject($this->takerFirstName.' is covering '.count($this->dates).' of your shifts')
                ->greeting('Hi '.$notifiable->firstName().',')
                ->line($this->takerFirstName.' took your '.$this->area.' shifts on these dates. You don\'t need to do anything.');

            foreach ($this->dates as $date) {
                $mail->line($date);
            }

            return $mail;
        }

        return (new MailMessage)
            ->subject($this->takerFirstName.' is covering your '.$this->date.' shift')
            ->greeting('Hi '.$notifiable->firstName().',')
            ->line($this->takerFirstName.' took your '.$this->area.' shift on '.$this->date.'. You don\'t need to do anything.');
    }
}
