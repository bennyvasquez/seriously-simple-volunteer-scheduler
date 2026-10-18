<?php

namespace App\Actions\Scheduling;

use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Adds one shift that isn't part of a weekly slot, such as an open house. Times are the organization's local wall-clock times. An end before the start means the shift ends the next day, the same rule as a weekly slot. Dates in the past are refused.
 */
class CreateOneTimeShift
{
    public function handle(User $actor, Area $area, string $localDate, string $startTime, string $endTime, int $headcount, int $shadowSpots, ?string $title): Shift
    {
        Gate::forUser($actor)->authorize('manageSchedule', $area);

        $organization = Organization::current();
        $start = CarbonImmutable::parse($startTime)->format('H:i');
        $end = CarbonImmutable::parse($endTime)->format('H:i');
        $title = $title === null || trim($title) === '' ? null : trim($title);

        if ($localDate < $organization->today()->toDateString()) {
            throw ValidationException::withMessages(['date' => 'Pick a date from today on.']);
        }

        if ($start === $end) {
            throw ValidationException::withMessages(['end_time' => 'A shift has to end at a different time than it starts.']);
        }

        if ($headcount < 1) {
            throw ValidationException::withMessages(['headcount' => 'At least 1 person is needed.']);
        }

        $endDate = $end < $start ? CarbonImmutable::parse($localDate, 'UTC')->addDay()->toDateString() : $localDate;

        $shift = new Shift([
            'area_id' => $area->id,
            'local_date' => $localDate,
            'starts_at' => CarbonImmutable::parse("{$localDate} {$start}", $organization->timezone)->utc(),
            'ends_at' => CarbonImmutable::parse("{$endDate} {$end}", $organization->timezone)->utc(),
            'headcount' => $headcount,
            'shadow_spots' => $shadowSpots,
            'title' => $title,
        ]);

        return DB::transaction(function () use ($actor, $shift) {
            $changes = ChangeLogEntry::changesOf($shift);
            $shift->save();

            ChangeLogEntry::record($actor, 'shift.created', $shift, ['area_id' => $shift->area_id], $changes);

            return $shift;
        });
    }
}
