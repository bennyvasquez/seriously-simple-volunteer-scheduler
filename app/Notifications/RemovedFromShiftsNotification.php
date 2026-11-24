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
        $count = count($this->shifts);

        if ($count === 1) {
            return (new MailMessage)
                ->subject(EmailText::choice('removed_from_shifts.subject', 1, ['area' => explode(' shift on ', $this->shifts[0])[0]]))
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
