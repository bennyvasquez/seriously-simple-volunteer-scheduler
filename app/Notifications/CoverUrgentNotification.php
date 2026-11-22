<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;

/**
 * The urgent email (wireframe 49): a released shift starts in less than 24 hours and nobody has taken it. Sent to the area's active leads and everyone with the coordinator role (decision 12). `$reason` says why this person gets it: "a shift lead for Front desk" or "a coordinator". When one action escalates several shifts, merge() folds them into one email per person.
 */
class CoverUrgentNotification extends Notification
{
    /** @var list<array{area: string, date: string, when: string, openPlaces: int, releaser: string, releasedOn: string, notifiedCount: int, resent: bool, url: string}> */
    public array $shifts;

    /** @var list<string> */
    public array $reasons;

    public function __construct(Shift $shift, string $releaserFirstName, CarbonImmutable $releasedAt, int $notifiedCount, bool $resent, string $reason)
    {
        $this->shifts = [[
            'area' => $shift->area->name,
            'date' => $shift->local_date->format('l, F j'),
            'when' => $shift->whenText(),
            'openPlaces' => $shift->openPlaces(),
            'releaser' => $releaserFirstName,
            'releasedOn' => $releasedAt->setTimezone(Organization::current()->timezone)->format('l, F j'),
            'notifiedCount' => $notifiedCount,
            'resent' => $resent,
            'url' => route('calendar.shift', $shift),
        ]];
        $this->reasons = [$reason];
    }

    /** Adds another escalated shift's email to this one, so the person gets one email listing both. */
    public function merge(self $other): self
    {
        $this->shifts = array_merge($this->shifts, $other->shifts);
        $this->reasons = array_values(array_unique(array_merge($this->reasons, $other->reasons)));

        return $this;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $reason = 'You\'re getting this because you\'re '.Arr::join($this->reasons, ', ', ' and ').'.';

        if (count($this->shifts) === 1) {
            $shift = $this->shifts[0];

            return (new MailMessage)
                ->subject('Needs someone tomorrow: '.$shift['area'].', '.$shift['date'])
                ->line('Nobody has taken this shift yet, and it starts in less than 24 hours.')
                ->line($shift['area'].', '.$shift['when'].'. '.self::needs($shift))
                ->line(self::released($shift))
                ->action('Open the shift', $shift['url'])
                ->line('It\'s marked urgent on the calendar until someone takes it. From the shift you can put someone on it yourself.')
                ->line($reason);
        }

        $mail = (new MailMessage)
            ->subject('Needs someone tomorrow: '.count($this->shifts).' shifts')
            ->line('Nobody has taken these shifts yet, and they start in less than 24 hours.');

        foreach ($this->shifts as $shift) {
            $mail->line('['.$shift['area'].', '.$shift['when'].']('.$shift['url'].'). '.self::needs($shift).' '.self::released($shift));
        }

        return $mail
            ->line('They\'re marked urgent on the calendar until someone takes them. From each shift you can put someone on it yourself.')
            ->line($reason);
    }

    private static function needs(array $shift): string
    {
        return 'Needs '.$shift['openPlaces'].' more '.Str::plural('person', $shift['openPlaces']).'.';
    }

    private static function released(array $shift): string
    {
        return $shift['releaser'].' released it on '.$shift['releasedOn'].'. We emailed '.$shift['notifiedCount'].' '.Str::plural('volunteer', $shift['notifiedCount']).' when it was released'.($shift['resent'] ? ' and again 72 hours before' : '').'.';
    }
}
