<?php

namespace App\Notifications;

use App\Calendar\TimeFormat;
use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Sent to a person on a shift whose time or title was changed. Takes a collection so it can carry several shifts, with one line each. Each item is `['shift' => Shift (with its new values and area), 'was' => the old time range such as "6 to 9pm", 'wasTitle' => the old title or null]`. */
class ShiftChangedNotification extends Notification
{
    /** @var list<string> One line per shift. */
    public array $lines;

    public string $subject;

    /** @param  Collection<int, array{shift: Shift, was: string, wasTitle: ?string}>  $changes */
    public function __construct(Collection $changes)
    {
        $this->lines = $changes->map(fn (array $change) => self::line($change))->values()->all();
        $this->subject = $changes->count() === 1 ? 'Your '.$changes->first()['shift']->area->name.' shift changed' : 'Your shifts changed';
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->subject($this->subject);

        foreach ($this->lines as $line) {
            $mail->line($line);
        }

        return $mail->line("If you can't make it, let your coordinator know.");
    }

    /** @param  array{shift: Shift, was: string, wasTitle: ?string}  $change */
    private static function line(array $change): string
    {
        $shift = $change['shift'];
        $timezone = Organization::current()->timezone;
        $start = $shift->starts_at->setTimezone($timezone);
        $now = TimeFormat::range($start, $shift->ends_at->setTimezone($timezone));
        $line = 'Your '.$shift->area->name.' shift on '.$start->format('l, F j').' changed.';

        if ($now !== $change['was']) {
            $line .= " It's now {$now}; it was {$change['was']}.";
        }

        if ($shift->title !== $change['wasTitle']) {
            $line .= $shift->title !== null ? " It's now for {$shift->title}." : " It's no longer for {$change['wasTitle']}.";
        }

        return $line;
    }
}
