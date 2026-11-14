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
        $this->shifts = $shifts->map(fn (Shift $shift) => $shift->area->name.' shift on '.$shift->whenText())->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (count($this->shifts) === 1) {
            return (new MailMessage)
                ->subject("Your {$this->area} shift is back on")
                ->line(self::backOnLines($this->shifts)[0]);
        }

        $mail = (new MailMessage)->subject("Your {$this->area} shifts are back on");

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
            return ["The {$shifts[0]} is back on, and you're still on it. If you can't make it, let your coordinator know."];
        }

        return [
            'These shifts are back on, and you\'re still on them:',
            ...array_map(fn (string $shift) => "The {$shift}.", $shifts),
            'If you can\'t make one, let your coordinator know.',
        ];
    }
}
