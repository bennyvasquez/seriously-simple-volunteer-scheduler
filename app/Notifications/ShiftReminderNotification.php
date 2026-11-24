<?php

namespace App\Notifications;

use App\Calendar\TimeFormat;
use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use IntlTimeZone;

/**
 * The reminder before a shift (spec section 10). Sent once to each person on the shift who can sign in and hasn't turned reminders off. Says whether the shift is today, tomorrow or on a date, worked out from the organization's date when the email is made, and names the organization's time zone in the body. `$others` and `$shadows` are short names (people on the shift other than this person, and shadows other than this person). `$changeBatch` is the batch of this person's open time change notice for the shift, when they haven't answered it, which adds the same two buttons the change email has. "Can't make it" is always offered: the person is on the shift, which is the condition the shift panel uses.
 */
class ShiftReminderNotification extends Notification
{
    public string $subject;

    public string $intro;

    public string $releaseUrl;

    public string $emailsUrl;

    public ?string $stillWorksUrl = null;

    /** @param  list<string>  $others  @param  list<string>  $shadows */
    public function __construct(Shift $shift, Assignment $assignment, public array $others, public array $shadows, public ?string $changeBatch)
    {
        $organization = Organization::current();
        $timezone = $organization->timezone;
        $start = $shift->starts_at->setTimezone($timezone);
        $time = TimeFormat::range($start, $shift->ends_at->setTimezone($timezone));
        $area = $shift->area->name;
        $today = $organization->today();

        $day = match (true) {
            $start->isSameDay($today) => 'today',
            $start->isSameDay($today->addDay()) => 'tomorrow',
            default => null,
        };

        $this->subject = 'Reminder: '.$area.' '.($day ?? 'on '.$start->format('l, F j')).', '.$time;
        $verb = $assignment->type === AssignmentType::Shadow ? 'shadowing' : 'on';
        $this->intro = 'You\'re '.$verb.' '.$area.' '.($day !== null ? $day.', ' : '').$start->format('l, F j').', '.$time.' ('.self::timezoneName($timezone).').';

        if ($shift->title !== null && $shift->title !== '') {
            $this->intro .= "\n\n".$shift->title;
        }

        $this->releaseUrl = route('shifts.release', $shift);
        $this->emailsUrl = route('account.emails');

        if ($changeBatch !== null) {
            $this->stillWorksUrl = route('changes.show', $changeBatch);
        }
    }

    /** "Eastern Time" for America/Detroit, or the identifier when the system has no name for it. */
    public static function timezoneName(string $timezone): string
    {
        $name = IntlTimeZone::createTimeZone($timezone)?->getDisplayName(false, IntlTimeZone::DISPLAY_LONG_GENERIC, 'en_US');

        return is_string($name) && $name !== '' ? $name : $timezone;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->greeting('Hi '.$notifiable->firstName().',')->markdown('mail.shift-reminder', [
            'intro' => $this->intro,
            'others' => $this->others === [] ? 'Nobody else is on this shift yet.' : 'Also on this shift: '.implode(', ', $this->others),
            'shadows' => $this->shadows === [] ? null : 'Shadowing: '.implode(', ', $this->shadows),
            'stillWorksUrl' => $this->stillWorksUrl,
            'cantUrl' => $this->stillWorksUrl,
            'releaseUrl' => $this->releaseUrl,
            'emailsUrl' => $this->emailsUrl,
        ]);
    }
}
