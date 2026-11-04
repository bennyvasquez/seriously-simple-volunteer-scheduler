<?php

namespace App\Actions\Scheduling;

use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes one shift's times, headcount, shadow spots or title. The date stays. Times are local wall-clock times, and an end before the start means the next day. Keys left out keep their current value.
 *
 * Only a shift nobody is on can be edited for now, since changing a shift someone is on needs the change notices from the editing plan. Sets edited_at, so later changes to its weekly slot leave it alone. Refuses a shift that has started or is cancelled. Saving without a change does nothing.
 */
class UpdateShift
{
    public const STAFFED = 'Someone is on this shift. Editing shifts with people on them comes soon; for now, cancel this date and add a one-time shift.';

    public function handle(User $actor, Shift $shift, array $attributes): Shift
    {
        $shift->loadMissing('area');
        Gate::forUser($actor)->authorize('update', $shift);

        $timezone = Organization::current()->timezone;

        return DB::transaction(function () use ($actor, $shift, $attributes, $timezone) {
            $fresh = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($fresh->cancelled_at !== null) {
                throw new DomainException('This shift is cancelled.');
            }

            if ($fresh->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            if ($fresh->assignments()->exists()) {
                throw new DomainException(self::STAFFED);
            }

            $start = CarbonImmutable::parse($attributes['start_time'] ?? $fresh->starts_at->setTimezone($timezone)->format('H:i'))->format('H:i');
            $end = CarbonImmutable::parse($attributes['end_time'] ?? $fresh->ends_at->setTimezone($timezone)->format('H:i'))->format('H:i');
            $headcount = (int) ($attributes['headcount'] ?? $fresh->headcount);
            $shadowSpots = (int) ($attributes['shadow_spots'] ?? $fresh->shadow_spots);
            $title = array_key_exists('title', $attributes) ? trim((string) $attributes['title']) : (string) $fresh->title;

            if ($start === $end) {
                throw ValidationException::withMessages(['end_time' => 'A shift has to end at a different time than it starts.']);
            }

            if ($headcount < 1) {
                throw ValidationException::withMessages(['headcount' => 'At least 1 person is needed.']);
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

            $fresh->fill([
                'starts_at' => $startsAt,
                'ends_at' => CarbonImmutable::parse("{$endDate} {$end}", $timezone)->utc(),
                'headcount' => $headcount,
                'shadow_spots' => $shadowSpots,
                'title' => $title === '' ? null : $title,
            ]);

            if ($fresh->isDirty()) {
                $fresh->edited_at = now();
                $changes = ChangeLogEntry::changesOf($fresh);
                $fresh->save();

                ChangeLogEntry::record($actor, 'shift.updated', $fresh, ['area_id' => $fresh->area_id], $changes);
            }

            $shift->setRawAttributes($fresh->getAttributes(), true);

            return $shift;
        });
    }
}
