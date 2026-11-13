<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Sent to someone a coordinator removed from shifts. Takes a collection so removing them from a range of dates sends one email; a single shift passes a one-item collection. */
class RemovedFromShiftsNotification extends Notification
{
    /** @var list<string> One "Front desk shift on Thursday, October 22, 6:30 to 9:30pm" per shift. */
    public array $shifts;

    /** @param  Collection<int, Shift>  $shifts */
    public function __construct(Collection $shifts)
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => $shift->area->name.' shift on '.$shift->whenText())->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (count($this->shifts) === 1) {
            return (new MailMessage)
                ->subject('You\'ve been removed from a '.explode(' shift on ', $this->shifts[0])[0].' shift')
                ->line("You're no longer on the {$this->shifts[0]}.")
                ->line('If you didn\'t expect this, ask your coordinator.');
        }

        $mail = (new MailMessage)
            ->subject('You\'ve been removed from '.count($this->shifts).' shifts')
            ->line('You\'re no longer on these shifts:');

        foreach ($this->shifts as $shift) {
            $mail->line("The {$shift}.");
        }

        return $mail->line('If you didn\'t expect this, ask your coordinator.');
    }
}
