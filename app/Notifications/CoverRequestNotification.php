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

        $mail = (new MailMessage)->greeting(EmailText::line('cover_request.greeting', ['first_name' => $notifiable->firstName()]));

        if ($this->stillOpen) {
            $mail->subject(EmailText::line('cover_request.subject_still_open', ['area' => $area, 'date' => $first['date']]))
                ->line(EmailText::line('cover_request.still_open', ['releaser' => $this->releaserFirstName, 'area' => $area, 'when' => $first['when']]));
        } elseif ($this->gaveUpSlot) {
            $mail->subject(EmailText::choice('cover_request.subject_gave_up', count($this->shifts), ['area' => $area, 'date' => $first['date'], 'weekdays' => $this->weekday().'s']))
                ->line(EmailText::line('cover_request.gave_up', ['releaser' => $this->releaserFirstName, 'weekday' => $this->weekday(), 'area' => $area]));
        } else {
            $mail->subject(EmailText::choice('cover_request.subject', count($this->shifts), ['area' => $area, 'date' => $first['date'], 'count' => count($this->shifts)]))
                ->line(EmailText::choice('cover_request.cant_make', count($this->shifts), ['releaser' => $this->releaserFirstName, 'area' => $area]));
        }

        if (! $this->stillOpen) {
            foreach ($this->shifts as $shift) {
                $mail->line($several ? EmailText::line('cover_request.shift_link', ['area' => $shift['area'], 'when' => $shift['when'], 'url' => $shift['url']]) : EmailText::line('cover_request.shift_item', ['area' => $shift['area'], 'when' => $shift['when']]));
            }
        }

        if ($this->note !== null && $this->note !== '') {
            $mail->line(EmailText::plainTextLine('cover_request.note', ['releaser' => $this->releaserFirstName, 'note' => $this->note]));
        }

        if (! $several) {
            $mail->action(EmailText::line('cover_request.action'), $first['url'])
                ->line(EmailText::line('cover_request.first_person_one'));
        } else {
            $mail->line(EmailText::line('cover_request.first_person_several'));
        }

        return $mail
            ->line(EmailText::line('cover_request.reason', ['organization' => $this->organization]))
            // The Emails page comes in a later task; this is its address.
            ->line(EmailText::line('cover_request.stop', ['url' => url('/account/emails')]));
    }

    private function weekday(): string
    {
        return explode(',', $this->shifts[0]['date'])[0];
    }
}
