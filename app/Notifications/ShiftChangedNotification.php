<?php

namespace App\Notifications;

use App\Calendar\TimeFormat;
use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/** Sent to a person on a shift whose time or title was changed. Takes a collection so it can carry several shifts, with one line each. Each item is `['shift' => Shift (with its new values and area), 'was' => the old time range such as "6 to 9pm", 'wasTitle' => the old title or null]`. */
class ShiftChangedNotification extends Notification
{
    /** @var list<string|HtmlString> One line per shift. */
    public array $lines;

    public string $subject;

    /** @param  Collection<int, array{shift: Shift, was: string, wasTitle: ?string}>  $changes */
    public function __construct(Collection $changes)
    {
        $this->lines = $changes->map(fn (array $change) => self::line($change))->values()->all();
        $this->subject = EmailText::choice('shift_changed.subject', $changes->count(), ['area' => $changes->first()['shift']->area->name]);
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

        return $mail->line(EmailText::line('shift_changed.outro'));
    }

    /** @param  array{shift: Shift, was: string, wasTitle: ?string}  $change */
    public static function line(array $change): string|HtmlString
    {
        $shift = $change['shift'];
        $timezone = Organization::current()->timezone;
        $start = $shift->starts_at->setTimezone($timezone);
        $now = TimeFormat::range($start, $shift->ends_at->setTimezone($timezone));
        $line = EmailText::line('shift_changed.changed', ['area' => $shift->area->name, 'date' => $start->format('l, F j')]);

        if ($now !== $change['was']) {
            $line = EmailText::join($line, ' ', EmailText::line('shift_changed.time', ['now' => $now, 'was' => $change['was']]));
        }

        return EmailText::join($line, self::titleSentence($shift, $change['wasTitle']));
    }

    /** " It's now for Open house.", " It's no longer for Open house.", or an empty string when the title didn't change. */
    public static function titleSentence(Shift $shift, ?string $wasTitle): string|HtmlString
    {
        if ($shift->title === $wasTitle) {
            return '';
        }

        return EmailText::join(' ', $shift->title !== null ? EmailText::line('shift_changed.title_now', ['title' => $shift->title]) : EmailText::line('shift_changed.title_removed', ['was_title' => $wasTitle]));
    }
}
