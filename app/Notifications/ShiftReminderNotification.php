<?php

namespace App\Notifications;

use App\Calendar\TimeFormat;
use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;
use IntlTimeZone;

/**
 * The reminder before a shift (spec section 10). Sent once to each person on the shift who can sign in and hasn't turned reminders off. Says whether the shift is today, tomorrow or on a date, worked out from the organization's date when the email is made, and names the organization's time zone in the body. `$others` and `$shadows` are short names (people on the shift other than this person, and shadows other than this person). `$changeBatch` is the batch of this person's open time change notice for the shift, when they haven't answered it, which adds the same two buttons the change email has. "Can't make it" is offered when the person is on the shift, which is the shift panel's own condition (ShiftController::volunteerAction()), except in two cases. With an open time change notice it's left out, because "I can't make the new time" and the answer page already offer it. And a shadow on a shift with no regulars (`Shift::filledCount() === 0`, the test CoverLadder uses for "nobody to shadow") has their spot on hold (spec section 8.2), so the email says nobody is there to shadow and to stay home if nobody takes it, with no button.
 */
class ShiftReminderNotification extends Notification
{
    public string $subject;

    public string|HtmlString $intro;

    public string $releaseUrl;

    public string $emailsUrl;

    public ?string $stillWorksUrl = null;

    /** True for a shadow on a shift nobody but shadows is on: their spot is on hold and the email asks them to check with the volunteer coordinators. */
    public bool $nobodyToShadow;

    public bool $showRelease;

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

        $when = $day ?? 'date';
        $date = $start->format('l, F j');
        $this->subject = EmailText::line('shift_reminder.subject.'.$when, ['area' => $area, 'date' => $date, 'time' => $time]);
        $role = $assignment->type === AssignmentType::Shadow ? 'shadow' : 'regular';
        $this->intro = EmailText::line('shift_reminder.intro.'.$role.'.'.$when, ['area' => $area, 'date' => $date, 'time' => $time, 'timezone' => self::timezoneName($timezone)]);

        if ($shift->title !== null && $shift->title !== '') {
            $this->intro = EmailText::join($this->intro, "\n\n", $shift->title);
        }

        $this->nobodyToShadow = $assignment->type === AssignmentType::Shadow && $shift->filledCount() === 0;
        $this->showRelease = $changeBatch === null && ! $this->nobodyToShadow;
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
        return (new MailMessage)->subject($this->subject)->greeting(EmailText::line('shift_reminder.greeting', ['first_name' => $notifiable->firstName()]))->markdown('mail.shift-reminder', [
            'intro' => $this->intro,
            'others' => match (true) {
                $this->nobodyToShadow => EmailText::line('shift_reminder.nobody_to_shadow'),
                $this->others === [] => EmailText::line('shift_reminder.nobody_else'),
                default => EmailText::line('shift_reminder.also_on', ['names' => implode(', ', $this->others)]),
            },
            'shadows' => $this->shadows === [] ? null : EmailText::line('shift_reminder.shadowing', ['names' => implode(', ', $this->shadows)]),
            'timeChanged' => EmailText::line('shift_reminder.time_changed'),
            'stillWorksLabel' => EmailText::line('shift_reminder.still_works'),
            'stillWorksUrl' => $this->stillWorksUrl,
            'cantLabel' => EmailText::line('shift_reminder.cant'),
            'cantUrl' => $this->stillWorksUrl,
            'loginNote' => EmailText::line('shift_reminder.login'),
            'releaseLabel' => EmailText::line('shift_reminder.release'),
            'releaseUrl' => $this->showRelease ? $this->releaseUrl : null,
            'turnOff' => EmailText::line('shift_reminder.turn_off', ['emails_url' => $this->emailsUrl]),
        ]);
    }
}
