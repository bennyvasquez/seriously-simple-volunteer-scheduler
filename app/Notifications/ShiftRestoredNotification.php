<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Sent to everyone still on shifts that were brought back: a shift a coordinator cancelled and then restored, or the shifts a changed or removed closure no longer covers. Takes one shift or a collection, so a closure change that brings back several shifts sends one email. */
class ShiftRestoredNotification extends Notification
{
    public string $area;

    /** @var list<string> One "Front desk shift on Thursday, October 22, 6:30 to 9:30pm" per shift. */
    public array $shifts;

    /** @param  Shift|Collection<int, Shift>  $shifts */
    public function __construct(Shift|Collection $shifts)
    {
        $shifts = $shifts instanceof Shift ? collect([$shifts]) : $shifts->values();
        $this->area = $shifts->first()->area->name;
        $this->shifts = $shifts->map(fn (Shift $shift) => EmailText::line('shift_restored.shift_phrase', ['area' => $shift->area->name, 'when' => $shift->whenText()]))->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (count($this->shifts) === 1) {
            return (new MailMessage)
                ->subject(EmailText::choice('shift_restored.subject', 1, ['area' => $this->area]))
                ->line(self::backOnLines($this->shifts)[0]);
        }

        $mail = (new MailMessage)->subject(EmailText::choice('shift_restored.subject', count($this->shifts), ['area' => $this->area]));

        foreach (self::backOnLines($this->shifts) as $line) {
            $mail->line($line);
        }

        return $mail;
    }

    /**
     * The email's lines for shifts that are back on, also used when one email says some shifts are cancelled and others are back on.
     *
     * @param  list<string>  $shifts
     * @return list<string>
     */
    public static function backOnLines(array $shifts): array
    {
        if (count($shifts) === 1) {
            return [EmailText::choice('shift_restored.body', 1, ['shift' => $shifts[0]])];
        }

        return [
            EmailText::choice('shift_restored.body', count($shifts)),
            ...array_map(fn (string $shift) => EmailText::line('shift_restored.shift_item', ['shift' => $shift]), $shifts),
            EmailText::line('shift_restored.outro'),
        ];
    }
}
