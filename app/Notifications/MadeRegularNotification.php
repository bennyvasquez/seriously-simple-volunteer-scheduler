<?php

namespace App\Notifications;

use App\Enums\SlotFrequency;
use App\Models\SlotRegular;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Sent to someone a coordinator or lead made a regular on a weekly slot. */
class MadeRegularNotification extends Notification
{
    public string $area;

    public string $slot;

    public string $how;

    public string $startingOn;

    public function __construct(WeeklySlot $slot, SlotRegular $regular, public int $shifts)
    {
        $this->area = $slot->area->name;
        $this->slot = $slot->describe();
        $this->how = $regular->frequency === SlotFrequency::EveryOtherWeek || $slot->frequency === SlotFrequency::EveryOtherWeek ? 'every other week' : 'every week';
        $this->startingOn = ($regular->firstWorkedDate() ?? CarbonImmutable::parse($regular->starts_on->toDateString(), 'UTC'))->format('l, F j');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject("You're now a regular for {$this->area}")
            ->line("You're now the regular for {$this->area} on {$this->slot}, {$this->how}, starting {$this->startingOn}.");

        if ($this->shifts > 0) {
            $mail->line("We've put you on ".($this->shifts === 1 ? '1 shift' : "{$this->shifts} shifts").' already on the schedule.');
        }

        return $mail;
    }
}
