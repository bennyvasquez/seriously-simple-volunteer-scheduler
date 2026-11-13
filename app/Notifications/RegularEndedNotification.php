<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Shift;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Sent to someone whose time as a regular on a weekly slot was ended. Lists only the future shifts they were removed from that weren't cancelled; nobody is told about past shifts. When the last date has passed, or was before the regular's start (`$beforeStart`), it says they're no longer the regular instead of naming the date. */
class RegularEndedNotification extends Notification
{
    public string $area;

    public string $weekdays;

    public string $lastDate;

    public bool $datePassed;

    /** @var list<string> One "Front desk shift on Thursday, October 22, 6:30 to 9:30pm" per shift. */
    public array $shifts;

    /** @param  Collection<int, Shift>  $futureShifts */
    public function __construct(WeeklySlot $slot, string $lastDate, Collection $futureShifts, bool $beforeStart = false)
    {
        $this->area = $slot->area->name;
        $this->weekdays = $slot->weekdayName().'s';
        $day = CarbonImmutable::parse($lastDate, 'UTC');
        $this->lastDate = $day->format('l, F j');
        $this->datePassed = $beforeStart || $lastDate < Organization::current()->today()->toDateString();
        $this->shifts = $futureShifts->map(fn (Shift $shift) => $this->area.' shift on '.$shift->whenText())->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("You're no longer the regular for {$this->area} on {$this->weekdays}")
            ->line($this->datePassed
                ? "You're no longer the regular for the {$this->area} shift on {$this->weekdays}."
                : "Your last date as the regular for the {$this->area} shift on {$this->weekdays} is {$this->lastDate}.");

        if ($this->shifts !== []) {
            $mail->line('You\'ve been removed from these shifts:');

            foreach ($this->shifts as $shift) {
                $mail->line("The {$shift}.");
            }
        }

        return $mail->line('If you didn\'t expect this, ask your coordinator.');
    }
}
