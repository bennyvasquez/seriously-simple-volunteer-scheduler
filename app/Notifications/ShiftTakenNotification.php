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
            ->subject('You\'re on '.$this->area.', '.$this->date)
            ->greeting('Hi '.$notifiable->firstName().($this->tookBack ? ', you\'re back on.' : ', thanks for covering!'));

        if ($this->regularHow !== null) {
            $mail->line('You\'re now the '.$this->regularHow.' regular for '.$this->area.', starting '.$this->date.'.');
        }

        $mail->line($this->when.', '.$this->area.'.');

        if (! $this->tookBack && $this->releaserFirstName !== null && $this->note !== null && $this->note !== '') {
            $mail->line(PlainText::line('Note from '.$this->releaserFirstName.': '.$this->note));
        }

        if ($this->shadowFirstNames !== []) {
            $mail->line(Arr::join($this->shadowFirstNames, ', ', ' and ').(count($this->shadowFirstNames) === 1 ? ' is' : ' are').' shadowing.');
        }

        if ($this->skippedDates !== []) {
            $mail->line('You\'re not on '.Arr::join($this->skippedDates, ', ', ' or ').' because you\'re already on another shift then.');
        }

        if ($this->fullDates !== []) {
            $mail->line('You\'re not on '.Arr::join($this->fullDates, ', ', ' or ').' because they already have the people they need.');
        }

        return $mail;
    }
}
