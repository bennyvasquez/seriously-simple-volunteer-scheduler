<?php

namespace App\Actions\Scheduling;

use App\Calendar\TimeFormat;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftChangedNotification;
use App\Notifications\ShiftTimeChangedNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes one shift's times, people needed, shadow spots or title. The date stays. Times are local wall-clock times, and an end before the start means the next day. Keys left out keep their current value.
 *
 * A shift with people on it can be edited too. StaffedShiftChecks refuses lowering the shadow spots below the shadows on it and a new time that double-books someone on it (shown beside the field), and lowering the people needed below the people on it is allowed. When the time or title changed, everyone on it who can sign in is emailed after the response: a changed time records a change notice for each of them (SendChangeNotices) and sends ShiftTimeChangedNotification, asking whether the new time works, while a changed title alone sends the plain ShiftChangedNotification; changing only the people needed or shadow spots emails nobody. Locks the area, then the shift, then reads the people and their other shifts. Sets edited_at, so later changes to its weekly slot leave it alone. Refuses a shift that has started or is cancelled. Saving without a change does nothing.
 */
class UpdateShift
{
    public function __construct(private SendChangeNotices $notices) {}

    public function handle(User $actor, Shift $shift, array $attributes): Shift
    {
        $shift->loadMissing('area');
        Gate::forUser($actor)->authorize('update', $shift);

        $timezone = Organization::current()->timezone;

        $emailed = collect();
        $changes = null;
        $timeNotices = [];

        $result = DB::transaction(function () use ($actor, $shift, $attributes, $timezone, &$emailed, &$changes, &$timeNotices) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $fresh = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($fresh->cancelled_at !== null) {
                throw new DomainException('This shift is cancelled.');
            }

            if ($fresh->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            $start = CarbonImmutable::parse($attributes['start_time'] ?? $fresh->starts_at->setTimezone($timezone)->format('H:i'))->format('H:i');
            $end = CarbonImmutable::parse($attributes['end_time'] ?? $fresh->ends_at->setTimezone($timezone)->format('H:i'))->format('H:i');
            $peopleNeeded = (int) ($attributes['people_needed'] ?? $fresh->people_needed);
            $shadowSpots = (int) ($attributes['shadow_spots'] ?? $fresh->shadow_spots);
            $title = array_key_exists('title', $attributes) ? trim((string) $attributes['title']) : (string) $fresh->title;

            if ($start === $end) {
                throw ValidationException::withMessages(['end_time' => 'A shift has to end at a different time than it starts.']);
            }

            if ($peopleNeeded < 1) {
                throw ValidationException::withMessages(['people_needed' => 'At least 1 person is needed.']);
            }

            if ($shadowSpots < 0) {
                throw ValidationException::withMessages(['shadow_spots' => 'Enter 0 if there are no shadow spots.']);
            }

            $date = $fresh->local_date->toDateString();
            $endDate = $end < $start ? CarbonImmutable::parse($date, 'UTC')->addDay()->toDateString() : $date;
            $startsAt = CarbonImmutable::parse("{$date} {$start}", $timezone)->utc();

            if ($startsAt->lessThanOrEqualTo(now())) {
                throw ValidationException::withMessages(['start_time' => 'Pick a start time that hasn\'t passed yet.']);
            }

            $wasRange = TimeFormat::range($fresh->starts_at->setTimezone($timezone), $fresh->ends_at->setTimezone($timezone));
            $wasTitle = $fresh->title;

            $fresh->fill([
                'starts_at' => $startsAt,
                'ends_at' => CarbonImmutable::parse("{$endDate} {$end}", $timezone)->utc(),
                'people_needed' => $peopleNeeded,
                'shadow_spots' => $shadowSpots,
                'title' => $title === '' ? null : $title,
            ]);

            if ($fresh->isDirty()) {
                $problem = StaffedShiftChecks::problem($actor, $fresh);

                if ($problem !== null) {
                    throw ValidationException::withMessages([$problem['field'] => $problem['message']]);
                }

                $notify = $fresh->isDirty(['starts_at', 'ends_at', 'title']);
                $timeChanged = $fresh->isDirty(['starts_at', 'ends_at']);
                $wasStartsAt = $fresh->getOriginal('starts_at');
                $wasEndsAt = $fresh->getOriginal('ends_at');
                $fresh->edited_at = now();
                $logged = ChangeLogEntry::changesOf($fresh);
                $fresh->save();

                ChangeLogEntry::record($actor, 'shift.updated', $fresh, ['area_id' => $fresh->area_id], $logged);

                if ($timeChanged) {
                    $timeNotices = $this->notices->recordLocked($actor, [['shift' => $fresh->load('area'), 'was_starts_at' => $wasStartsAt, 'was_ends_at' => $wasEndsAt]]);
                }

                if ($notify && ! $timeChanged) {
                    $emailed = $fresh->assignments()->with('user')->get()->pluck('user')->filter(fn (User $user) => $user->canSignIn())->values();
                    $changes = collect([['shift' => $fresh->load('area'), 'was' => $wasRange, 'wasTitle' => $wasTitle]]);
                }
            }

            $shift->setRawAttributes($fresh->getAttributes(), true);

            return $shift;
        });

        if ($changes !== null && $emailed->isNotEmpty()) {
            defer(fn () => $emailed->each(fn (User $person) => $person->notify(new ShiftChangedNotification($changes))));
        }

        if ($timeNotices !== []) {
            defer(fn () => collect($timeNotices)->each(fn (array $sent) => $sent['user']->notify($sent['notification'])));
        }

        return $result;
    }
}
