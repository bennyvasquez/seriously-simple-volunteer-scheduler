<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/** Sent to everyone on shifts that were cancelled. Takes a collection so a closure that cancels several shifts sends one email; a single cancelled date passes a one-item collection. When a changed closure also brings back other shifts the person is on, those come in the same email, so nobody gets two. */
class ShiftCancelledNotification extends Notification
{
    /** @var list<string|HtmlString> One "Front desk shift on Thursday, October 22, 6:30 to 9:30pm" per shift. */
    public array $shifts;

    /** @var list<string|HtmlString> The same for each shift that is back on, when a changed closure brought some back. */
    public array $restored;

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, Shift>|null  $restored
     */
    public function __construct(Collection $shifts, public string $reason, ?Collection $restored = null)
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => EmailText::line('shift_cancelled.shift_phrase', ['area' => $shift->area->name, 'when' => $shift->whenText()]))->values()->all();
        $this->restored = ($restored ?? collect())->map(fn (Shift $shift) => EmailText::line('shift_restored.shift_phrase', ['area' => $shift->area->name, 'when' => $shift->whenText()]))->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(EmailText::choice('shift_cancelled.subject', count($this->shifts)));

        foreach (self::cancelledLines($this->shifts, $this->reason) as $line) {
            $mail->line($line);
        }

        if ($this->restored !== []) {
            $mail->subject(EmailText::line('shift_cancelled.subject_changes'));

            foreach (ShiftRestoredNotification::backOnLines($this->restored) as $line) {
                $mail->line($line);
            }
        }

        return $mail;
    }

    /**
     * The email's lines for cancelled shifts, also used when the change notice email carries them.
     *
     * @param  list<string|HtmlString>  $shifts
     * @return list<string|HtmlString>
     */
    public static function cancelledLines(array $shifts, string $reason): array
    {
        $sentence = self::sentence($reason);

        if (count($shifts) === 1) {
            return [EmailText::choice('shift_cancelled.body', 1, ['shift' => $shifts[0], 'reason' => $sentence])];
        }

        return [
            EmailText::choice('shift_cancelled.body', count($shifts), ['reason' => $sentence]),
            ...array_map(fn (string|HtmlString $shift) => EmailText::line('shift_cancelled.shift_item', ['shift' => $shift]), $shifts),
            EmailText::line('shift_cancelled.outro'),
        ];
    }

    /** The reason as a sentence: a period is added unless it already ends with one, or with a question or exclamation mark. */
    private static function sentence(string $reason): string
    {
        $reason = trim($reason);

        return preg_match('/[.!?]$/', $reason) === 1 ? $reason : $reason.'.';
    }
}
