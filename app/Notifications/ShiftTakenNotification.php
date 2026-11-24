<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;

/**
 * Sent to a volunteer who took an open shift (wireframe 45). Names the release note's writer and the note when it fills a cover request, and who is shadowing. When they became the regular, `$regularHow` is how often ("Thursday" or "every other Thursday") and the email covers the slot and its first date in one, with `$skippedDates` ("Thursday, October 29") left out because they're on another shift then and `$fullDates` left out because they already have the people they need. `$tookBack` is someone taking back their own released shift (decision 7).
 */
class ShiftTakenNotification extends Notification
{
    public string $area;

    public string $date;

    public string $when;

    /**
     * @param  list<string>  $shadowFirstNames
     * @param  list<string>  $skippedDates
     * @param  list<string>  $fullDates
     */
    public function __construct(Shift $shift, public ?string $releaserFirstName = null, public ?string $note = null, public array $shadowFirstNames = [], public bool $tookBack = false, public ?string $regularHow = null, public array $skippedDates = [], public array $fullDates = [])
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
        $this->when = $shift->whenText();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(EmailText::line('shift_taken.subject', ['area' => $this->area, 'date' => $this->date]))
            ->greeting(EmailText::line($this->tookBack ? 'shift_taken.greeting_back_on' : 'shift_taken.greeting_thanks', ['first_name' => $notifiable->firstName()]));

        if ($this->regularHow !== null) {
            $mail->line(EmailText::line('shift_taken.regular', ['how' => $this->regularHow, 'area' => $this->area, 'date' => $this->date]));
        }

        $mail->line(EmailText::line('shift_taken.when_area', ['when' => $this->when, 'area' => $this->area]));

        if (! $this->tookBack && $this->releaserFirstName !== null && $this->note !== null && $this->note !== '') {
            $mail->line(PlainText::line(EmailText::line('shift_taken.note', ['releaser' => $this->releaserFirstName, 'note' => $this->note])));
        }

        if ($this->shadowFirstNames !== []) {
            $mail->line(EmailText::choice('shift_taken.shadowing', count($this->shadowFirstNames), ['names' => Arr::join($this->shadowFirstNames, ', ', ' and ')]));
        }

        if ($this->skippedDates !== []) {
            $mail->line(EmailText::line('shift_taken.not_on_other_shift', ['dates' => Arr::join($this->skippedDates, ', ', ' or ')]));
        }

        if ($this->fullDates !== []) {
            $mail->line(EmailText::line('shift_taken.not_on_full', ['dates' => Arr::join($this->fullDates, ', ', ' or ')]));
        }

        return $mail;
    }
}
