<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * The cover request: asks a volunteer to take a shift someone can't make (wireframes 42 and 48). One email per person, listing every shift when a range or a weekly slot was released. `$stillOpen` is the "Still open" email the ladder sends 72 hours before, and `$gaveUpSlot` the wording for someone giving up their weekly slot.
 */
class CoverRequestNotification extends Notification
{
    /** @var list<array{area: string, date: string, when: string, url: string}> */
    public array $shifts;

    public string $organization;

    /** @param  Collection<int, Shift>  $shifts  With their areas loaded. */
    public function __construct(Collection $shifts, public string $releaserFirstName, public ?string $note, public bool $stillOpen = false, public bool $gaveUpSlot = false)
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => [
            'area' => $shift->area->name,
            'date' => $shift->local_date->format('l, F j'),
            'when' => $shift->whenText(),
            // The take page comes in a later task; this is its address.
            'url' => route('shifts.take', $shift),
        ])->values()->all();
        $this->organization = Organization::current()->name;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $first = $this->shifts[0];
        $area = $first['area'];
        $several = count($this->shifts) > 1;

        $mail = (new MailMessage)->greeting('Hi '.$notifiable->firstName().',');

        if ($this->stillOpen) {
            $mail->subject('Still open: '.$first['area'].' on '.$first['date'])
                ->line('Nobody has taken '.$this->releaserFirstName.'\'s '.$area.' shift yet. It\'s '.$first['when'].'.');
        } elseif ($this->gaveUpSlot) {
            $mail->subject('Can you cover '.$area.' on '.($several ? 'some '.$this->weekday().'s' : $first['date']).'?')
                ->line($this->releaserFirstName.' is giving up their weekly '.$this->weekday().' '.$area.' slot. These dates need someone:');
        } else {
            $mail->subject('Can you cover '.$area.($several ? ' on '.count($this->shifts).' dates?' : ' on '.$first['date'].'?'))
                ->line($this->releaserFirstName.' can\'t make their '.$area.' '.($several ? 'shifts' : 'shift').'. Can you take '.($several ? 'one?' : 'it?'));
        }

        if (! $this->stillOpen) {
            foreach ($this->shifts as $shift) {
                $mail->line($several ? '['.$shift['area'].', '.$shift['when'].']('.$shift['url'].')' : $shift['area'].', '.$shift['when']);
            }
        }

        if ($this->note !== null && $this->note !== '') {
            $mail->line(PlainText::line('Note from '.$this->releaserFirstName.': '.$this->note));
        }

        if (! $several) {
            $mail->action('Take this shift', $first['url'])
                ->line('The first person to take it gets it. You\'ll log in first if you aren\'t already.');
        } else {
            $mail->line('Pick a date to take it. The first person to take each date gets it. You\'ll log in first if you aren\'t already.');
        }

        return $mail
            ->line('You\'re getting this because you volunteer with '.$this->organization.' and cover request emails are on for you.')
            // The Emails page comes in a later task; this is its address.
            ->line('[Stop getting cover requests]('.url('/account/emails').')');
    }

    private function weekday(): string
    {
        return explode(',', $this->shifts[0]['date'])[0];
    }
}
