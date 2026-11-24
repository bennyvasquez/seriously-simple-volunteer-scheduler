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
        $count = count($this->dates);
        $vars = ['taker' => $this->takerFirstName, 'count' => $count, 'area' => $this->area, 'date' => $this->date];

        $mail = (new MailMessage)
            ->subject(EmailText::choice('shift_covered.subject', $count, $vars))
            ->greeting(EmailText::line('shift_covered.greeting', ['first_name' => $notifiable->firstName()]))
            ->line(EmailText::choice('shift_covered.body', $count, $vars));

        if ($count > 1) {
            foreach ($this->dates as $date) {
                $mail->line($date);
            }
        }

        return $mail;
    }
}
