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
 * Sent to a person on shifts whose time changed (screen 56). One email covers every shift one edit moved for that person, and asks whether the new time still works, with two buttons: "Still works for me" (the answer page for the batch) and "I can't make the new time" ("Can't make it" for the earliest shift). Both need a login. A change made by the system, with no one to name, says the area's schedule changed. Items are `['shift' => Shift (new values, area loaded), 'was_starts_at' => CarbonImmutable, 'was_ends_at' => CarbonImmutable]`.
 */
class ShiftTimeChangedNotification extends Notification
{
    public string $subject;

    public string $intro;

    /** @var list<string> */
    public array $lines;

    public string $stillWorksUrl;

    public string $cantUrl;

    /** @param  Collection<int, array{shift: Shift, was_starts_at: CarbonImmutable, was_ends_at: CarbonImmutable}>  $items */
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
        ]);

        $sameNow = $rows->pluck('now')->unique()->count() === 1;
        $sameWas = $rows->pluck('was')->unique()->count() === 1;
        $sameSlot = $first->weekly_slot_id !== null && $items->every(fn (array $item) => $item['shift']->weekly_slot_id === $first->weekly_slot_id);

        $this->subject = $sameNow && $rows->pluck('shift.area_id')->unique()->count() === 1
            ? "Your {$area} shift moved to {$rows->first()['now']}"
            : 'Your shifts changed';

        $this->intro = $changedByName !== null
            ? $changedByName.' changed the time of your '.$area.' '.($several ? 'shifts.' : 'shift.')
            : "The {$area} schedule changed.";

        if ($several && $sameNow && $sameWas && $sameSlot) {
            $row = $rows->first();
            $this->lines = [$row['date']->format('l, F j').', and every '.$row['date']->format('l').' after: '.$row['now'].' (was '.$row['was'].')'];
        } else {
            $this->lines = $rows->map(fn (array $row) => $row['date']->format('l, F j').': '.$row['now'].' (was '.$row['was'].')')->all();
        }

        $this->stillWorksUrl = route('changes.show', $batch);
        $this->cantUrl = route('shifts.release', $first);
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
            'stillWorksUrl' => $this->stillWorksUrl,
            'cantUrl' => $this->cantUrl,
        ]);
    }
}
