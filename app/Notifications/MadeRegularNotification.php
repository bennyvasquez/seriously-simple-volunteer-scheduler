<?php

namespace App\Notifications;

use App\Enums\SlotFrequency;
use App\Models\SlotRegular;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/** Sent to someone a coordinator or lead made a regular on a weekly slot. */
class MadeRegularNotification extends Notification
{
    public string $area;

    public string $slot;

    /** "every" or "every other", from the wording file or the organization's edit. */
    public string|HtmlString $how;

    public string $startingOn;

    public function __construct(WeeklySlot $slot, SlotRegular $regular, public int $shifts)
    {
        $this->area = $slot->area->name;
        $this->slot = $slot->describe();
        $this->how = EmailText::line($regular->frequency === SlotFrequency::EveryOtherWeek || $slot->frequency === SlotFrequency::EveryOtherWeek ? 'made_regular.how.every_other_week' : 'made_regular.how.every_week');
        $this->startingOn = ($regular->firstWorkedDate() ?? CarbonImmutable::parse($regular->starts_on->toDateString(), 'UTC'))->format('l, F j');
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(EmailText::line('made_regular.subject', ['area' => $this->area]))
            ->line(EmailText::line('made_regular.intro', ['area' => $this->area, 'slot' => $this->slot, 'how' => $this->how, 'starting_on' => $this->startingOn]));

        if ($this->shifts > 0) {
            $mail->line(EmailText::choice('made_regular.shifts', $this->shifts, ['count' => $this->shifts]));
        }

        return $mail;
    }
}
