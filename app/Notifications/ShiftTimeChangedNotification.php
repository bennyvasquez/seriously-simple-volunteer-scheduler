<?php

namespace App\Notifications;

use App\Actions\Scheduling\SendChangeHeadsUps;
use App\Calendar\TimeFormat;
use App\Models\Organization;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * Sent to a person on shifts whose time changed (screen 56). One email covers every shift one edit moved for that person, and asks whether the new time still works, with two buttons: "Still works for me" (the answer page for the batch) and "I can't make the new time" ("Can't make it" for the earliest shift). Both need a login, and "I can't make the new time" opens the answer page, which offers the release link for the earliest shift that hasn't started, so it still works a week later. When the earliest shift starts within the heads-up window (SendChangeHeadsUps::HOURS) the email leaves out "your shift lead will check with you", since no heads-up goes out then. A change made by the system, with no one to name, says the area's schedule changed. Items are `['shift' => Shift (new values, area loaded), 'was_starts_at' => CarbonImmutable, 'was_ends_at' => CarbonImmutable, 'was_title' => ?string (optional: when the shift's title changed too, its line says so), 'back_on' => bool (optional: the shift was cancelled and came back at a new time, which the intro says)]`. withTitleChanges() adds lines, after the question and buttons, for the person's other shifts in the same edit whose title alone changed, worded like ShiftChangedNotification's lines, so each person still gets one email.
 */
class ShiftTimeChangedNotification extends Notification
{
    public string $subject;

    public string|HtmlString $intro;

    /** @var list<string|HtmlString> */
    public array $lines;

    /** @var list<string|HtmlString> Lines for shifts whose title alone changed. */
    public array $titleLines = [];

    /** @var list<int> The ids of the shifts the email asks about. */
    public array $shiftIds;

    /** Whether the email says the shift lead will check with the person before the shift. */
    public bool $leadChecks;

    public string $stillWorksUrl;

    public string $cantUrl;

    /** @param  Collection<int, array{shift: Shift, was_starts_at: CarbonImmutable, was_ends_at: CarbonImmutable, was_title?: ?string}>  $items */
    public function __construct(public string $batch, Collection $items, ?string $changedByName)
    {
        $timezone = Organization::current()->timezone;
        $items = $items->sortBy(fn (array $item) => $item['shift']->starts_at->getTimestamp())->values();
        $first = $items->first()['shift'];
        $area = $first->area->name;
        $several = $items->count() > 1;
        $backOn = $items->every(fn (array $item) => ($item['back_on'] ?? false) === true);
        $this->shiftIds = $items->map(fn (array $item) => $item['shift']->id)->all();
        $this->leadChecks = $first->starts_at->greaterThan(now()->addHours(SendChangeHeadsUps::HOURS));

        $rows = $items->map(fn (array $item) => [
            'shift' => $item['shift'],
            'date' => $item['shift']->starts_at->setTimezone($timezone),
            'now' => TimeFormat::range($item['shift']->starts_at->setTimezone($timezone), $item['shift']->ends_at->setTimezone($timezone)),
            'was' => TimeFormat::range($item['was_starts_at']->setTimezone($timezone), $item['was_ends_at']->setTimezone($timezone)),
            'titleSentence' => array_key_exists('was_title', $item) ? ShiftChangedNotification::titleSentence($item['shift'], $item['was_title']) : '',
        ]);

        $sameNow = $rows->pluck('now')->unique()->count() === 1;
        $sameWas = $rows->pluck('was')->unique()->count() === 1;
        $this->subject = $sameNow && $rows->pluck('shift.area_id')->unique()->count() === 1
            ? EmailText::line($backOn ? 'shift_time_changed.subject.back_on' : 'shift_time_changed.subject.moved', ['area' => $area, 'time' => $rows->first()['now']])
            : EmailText::line($backOn ? 'shift_time_changed.subject.back_on_several' : 'shift_time_changed.subject.changed');

        $this->intro = match (true) {
            $backOn && ! $several => EmailText::line('shift_time_changed.intro.back_on_one', ['area' => $area, 'date' => $rows->first()['date']->format('l, F j')]),
            $backOn => EmailText::line('shift_time_changed.intro.back_on_several', ['area' => $area]),
            $changedByName !== null => EmailText::line($several ? 'shift_time_changed.intro.changed_several' : 'shift_time_changed.intro.changed_one', ['changed_by' => $changedByName, 'area' => $area]),
            default => EmailText::line('shift_time_changed.intro.by_system', ['area' => $area]),
        };

        if ($several && $sameNow && $sameWas && $rows->every(fn (array $row) => $row['titleSentence'] === '') && self::consecutiveDatesOfOneSlot($items->pluck('shift'))) {
            $row = $rows->first();
            $last = $rows->last()['date'];
            $this->lines = [EmailText::line('shift_time_changed.range', ['weekdays' => $row['date']->format('l').'s', 'from' => $row['date']->format('F j'), 'to' => $last->format('F j'), 'now' => $row['now'], 'was' => $row['was']])];
        } else {
            $this->lines = $rows->map(fn (array $row) => $row['titleSentence'] === ''
                ? EmailText::line('shift_time_changed.line', ['date' => $row['date']->format('l, F j'), 'now' => $row['now'], 'was' => $row['was']])
                : EmailText::line('shift_time_changed.line_with_title', ['date' => $row['date']->format('l, F j'), 'now' => $row['now'], 'was' => $row['was'], 'title_sentence' => $row['titleSentence']]))->all();
        }

        $this->stillWorksUrl = route('changes.show', $batch);
        // The answer page offers "I can't make the new time" for the earliest shift still ahead, so a link to the first shift can't go stale.
        $this->cantUrl = $this->stillWorksUrl;
    }

    /**
     * True when the shifts are the same weekly slot and are every date that slot has from the first to the last, so "Thursdays from October 22 to December 10" is the real range: nothing between them was kept, cancelled or left out.
     *
     * @param  Collection<int, Shift>  $shifts  Earliest first.
     */
    public static function consecutiveDatesOfOneSlot(Collection $shifts): bool
    {
        $slotId = $shifts->first()->weekly_slot_id;

        if ($slotId === null || $shifts->contains(fn (Shift $shift) => $shift->weekly_slot_id !== $slotId)) {
            return false;
        }

        $dates = $shifts->map(fn (Shift $shift) => $shift->local_date->toDateString());
        $slot = $shifts->first()->weeklySlot;
        $between = $slot->datesWithin($shifts->first()->local_date, $shifts->last()->local_date);

        return $between === $dates->all();
    }

    /** Adds a line, after the question and buttons, for each of the person's shifts in this edit whose title alone changed. @param  Collection<int, array{shift: Shift, was: string, wasTitle: ?string}>  $changes */
    public function withTitleChanges(Collection $changes): static
    {
        return $this->withMoreLines($changes->map(fn (array $change) => ShiftChangedNotification::line($change))->values()->all());
    }

    /**
     * Adds lines after the question and buttons, so one email carries everything the person is told about an edit (their other shifts' title changes, shifts that are back on without a time change, shifts a closure cancelled).
     *
     * @param  list<string|HtmlString>  $lines
     */
    public function withMoreLines(array $lines): static
    {
        $this->titleLines = [...$this->titleLines, ...$lines];

        return $this;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->greeting(EmailText::line('shift_time_changed.greeting', ['first_name' => $notifiable->firstName()]))->markdown('mail.change-notice', [
            'intro' => $this->intro,
            'lines' => $this->lines,
            'titleLines' => $this->titleLines,
            'question' => EmailText::line('shift_time_changed.question'),
            'stillWorksLabel' => EmailText::line('shift_time_changed.still_works'),
            'stillWorksUrl' => $this->stillWorksUrl,
            'cantLabel' => EmailText::line('shift_time_changed.cant'),
            'cantUrl' => $this->cantUrl,
            'loginNote' => $this->leadChecks ? EmailText::join(EmailText::line('shift_time_changed.login'), ' ', EmailText::line('shift_time_changed.lead_checks')) : EmailText::line('shift_time_changed.login'),
        ]);
    }
}
