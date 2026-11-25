<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/** Sent to someone a coordinator removed from shifts. Takes a collection so removing them from a range of dates sends one email; a single shift passes a one-item collection. */
class RemovedFromShiftsNotification extends Notification
{
    /** @var list<string|HtmlString> One "Front desk shift on Thursday, October 22, 6:30 to 9:30pm" per shift. */
    public array $shifts;

    /** The first shift's area name, for the subject when there's one shift. */
    public string $area;

    /** @param  Collection<int, Shift>  $shifts */
    public function __construct(Collection $shifts)
    {
        $this->area = $shifts->first()->area->name;
        $this->shifts = $shifts->map(fn (Shift $shift) => EmailText::line('removed_from_shifts.shift_phrase', ['area' => $shift->area->name, 'when' => $shift->whenText()]))->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $count = count($this->shifts);

        if ($count === 1) {
            return (new MailMessage)
                ->subject(EmailText::choice('removed_from_shifts.subject', 1, ['area' => $this->area]))
                ->line(EmailText::choice('removed_from_shifts.intro', 1, ['shift' => $this->shifts[0]]))
                ->line(EmailText::line('removed_from_shifts.outro'));
        }

        $mail = (new MailMessage)
            ->subject(EmailText::choice('removed_from_shifts.subject', $count, ['count' => $count]))
            ->line(EmailText::choice('removed_from_shifts.intro', $count));

        foreach ($this->shifts as $shift) {
            $mail->line(EmailText::line('removed_from_shifts.shift_item', ['shift' => $shift]));
        }

        return $mail->line(EmailText::line('removed_from_shifts.outro'));
    }
}
