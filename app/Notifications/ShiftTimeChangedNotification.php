<?php

namespace App\Notifications;

use App\Calendar\TimeFormat;
use App\Models\Organization;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Sent to a person on shifts whose time changed (screen 56). One email covers every shift one edit moved for that person, and asks whether the new time still works, with two buttons: "Still works for me" (the answer page for the batch) and "I can't make the new time" ("Can't make it" for the earliest shift). Both need a login. A change made by the system, with no one to name, says the area's schedule changed. Items are `['shift' => Shift (new values, area loaded), 'was_starts_at' => CarbonImmutable, 'was_ends_at' => CarbonImmutable, 'was_title' => ?string (optional: when the shift's title changed too, its line says so)]`. withTitleChanges() adds lines, after the question and buttons, for the person's other shifts in the same edit whose title alone changed, worded like ShiftChangedNotification's lines, so each person still gets one email.
 */
class ShiftTimeChangedNotification extends Notification
{
    public string $subject;

    public string $intro;

    /** @var list<string> */
    public array $lines;

    /** @var list<string> Lines for shifts whose title alone changed. */
    public array $titleLines = [];

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
            ? "Your {$area} shift moved to {$rows->first()['now']}"
            : 'Your shifts changed';

        $this->intro = $changedByName !== null
            ? $changedByName.' changed the time of your '.$area.' '.($several ? 'shifts.' : 'shift.')
            : "The {$area} schedule changed.";

        if ($several && $sameNow && $sameWas && $rows->every(fn (array $row) => $row['titleSentence'] === '') && self::consecutiveDatesOfOneSlot($items)) {
            $row = $rows->first();
            $last = $rows->last()['date'];
            $this->lines = [$row['date']->format('l').'s from '.$row['date']->format('F j').' to '.$last->format('F j').': '.$row['now'].' (was '.$row['was'].')'];
        } else {
            $this->lines = $rows->map(fn (array $row) => $row['date']->format('l, F j').': '.$row['now'].' (was '.$row['was'].')'.($row['titleSentence'] === '' ? '' : '.'.$row['titleSentence']))->all();
        }

        $this->stillWorksUrl = route('changes.show', $batch);
        $this->cantUrl = route('shifts.release', $first);
    }

    /**
     * True when the shifts are the same weekly slot and are every date that slot has from the first to the last, so "Thursdays from October 22 to December 10" is the real range: nothing between them was kept, cancelled or left out.
     *
     * @param  Collection<int, array{shift: Shift}>  $items  Earliest first.
     */
    private static function consecutiveDatesOfOneSlot(Collection $items): bool
    {
        $slotId = $items->first()['shift']->weekly_slot_id;

        if ($slotId === null || $items->contains(fn (array $item) => $item['shift']->weekly_slot_id !== $slotId)) {
            return false;
        }

        $dates = $items->map(fn (array $item) => $item['shift']->local_date->toDateString());
        $slot = $items->first()['shift']->weeklySlot;
        $between = $slot->datesWithin($items->first()['shift']->local_date, $items->last()['shift']->local_date);

        return $between === $dates->all();
    }

    /** Adds a line, after the question and buttons, for each of the person's shifts in this edit whose title alone changed. @param  Collection<int, array{shift: Shift, was: string, wasTitle: ?string}>  $changes */
    public function withTitleChanges(Collection $changes): static
    {
        $this->titleLines = $changes->map(fn (array $change) => ShiftChangedNotification::line($change))->values()->all();

        return $this;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)->subject($this->subject)->greeting('Hi '.$notifiable->firstName().',')->markdown('mail.change-notice', [
            'intro' => $this->intro,
            'lines' => $this->lines,
            'titleLines' => $this->titleLines,
            'stillWorksUrl' => $this->stillWorksUrl,
            'cantUrl' => $this->cantUrl,
        ]);
    }
}
