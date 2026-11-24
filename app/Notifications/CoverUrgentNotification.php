<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\HtmlString;

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
        $reason = EmailText::line('cover_urgent.reason', ['reasons' => Arr::join($this->reasons, ', ', ' and ')]);

        if (count($this->shifts) === 1) {
            $shift = $this->shifts[0];

            return (new MailMessage)
                ->subject(EmailText::line('cover_urgent.subject.one', ['area' => $shift['area'], 'date' => $shift['date']]))
                ->line(EmailText::line('cover_urgent.unclaimed.one'))
                ->line(EmailText::line('cover_urgent.shift_line', ['area' => $shift['area'], 'when' => $shift['when'], 'needs' => self::needs($shift)]))
                ->line(self::released($shift))
                ->action(EmailText::line('cover_urgent.action'), $shift['url'])
                ->line(EmailText::line('cover_urgent.marked_urgent.one'))
                ->line($reason);
        }

        $mail = (new MailMessage)
            ->subject(EmailText::line('cover_urgent.subject.other', ['count' => count($this->shifts)]))
            ->line(EmailText::line('cover_urgent.unclaimed.other'));

        foreach ($this->shifts as $shift) {
            $mail->line(EmailText::line('cover_urgent.shift_link_line', ['area' => $shift['area'], 'when' => $shift['when'], 'url' => $shift['url'], 'needs' => self::needs($shift), 'released' => self::released($shift)]));
        }

        return $mail
            ->line(EmailText::line('cover_urgent.marked_urgent.other'))
            ->line($reason);
    }

    private static function needs(array $shift): string|HtmlString
    {
        return EmailText::choice('cover_urgent.needs', $shift['openPlaces'], ['count' => $shift['openPlaces']]);
    }

    private static function released(array $shift): string|HtmlString
    {
        $count = $shift['notifiedCount'];

        return EmailText::join(EmailText::line('cover_urgent.released', ['releaser' => $shift['releaser'], 'released_on' => $shift['releasedOn']]), ' ', EmailText::choice($shift['resent'] ? 'cover_urgent.emailed_again' : 'cover_urgent.emailed', $count, ['count' => $count]));
    }
}
