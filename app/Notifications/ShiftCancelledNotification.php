<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Sent to everyone on shifts that were cancelled. Takes a collection so a closure that cancels several shifts sends one email; a single cancelled date passes a one-item collection. When a changed closure also brings back other shifts the person is on, those come in the same email, so nobody gets two. */
class ShiftCancelledNotification extends Notification
{
    /** @var list<string> One "Front desk shift on Thursday, October 22, 6:30 to 9:30pm" per shift. */
    public array $shifts;

    /** @var list<string> The same for each shift that is back on, when a changed closure brought some back. */
    public array $restored;

    /**
     * @param  Collection<int, Shift>  $shifts
     * @param  Collection<int, Shift>|null  $restored
     */
    public function __construct(Collection $shifts, public string $reason, ?Collection $restored = null)
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => $shift->area->name.' shift on '.$shift->whenText())->values()->all();
        $this->restored = ($restored ?? collect())->map(fn (Shift $shift) => $shift->area->name.' shift on '.$shift->whenText())->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject(count($this->shifts) === 1 ? 'A shift you were on is cancelled' : 'Shifts you were on are cancelled');

        foreach (self::cancelledLines($this->shifts, $this->reason) as $line) {
            $mail->line($line);
        }

        if ($this->restored !== []) {
            $mail->subject('Changes to your shifts');

            foreach (ShiftRestoredNotification::backOnLines($this->restored) as $line) {
                $mail->line($line);
            }
        }

        return $mail;
    }

    /**
     * The email's lines for cancelled shifts, also used when the change notice email carries them.
     *
     * @param  list<string>  $shifts
     * @return list<string>
     */
    public static function cancelledLines(array $shifts, string $reason): array
    {
        $sentence = self::sentence($reason);

        if (count($shifts) === 1) {
            return ["The {$shifts[0]} is cancelled: {$sentence} You don't need to do anything."];
        }

        return ["These shifts are cancelled: {$sentence}", ...array_map(fn (string $shift) => "The {$shift}.", $shifts), "You don't need to do anything."];
    }

    /** The reason as a sentence: a period is added unless it already ends with one, or with a question or exclamation mark. */
    private static function sentence(string $reason): string
    {
        $reason = trim($reason);

        return preg_match('/[.!?]$/', $reason) === 1 ? $reason : $reason.'.';
    }
}
