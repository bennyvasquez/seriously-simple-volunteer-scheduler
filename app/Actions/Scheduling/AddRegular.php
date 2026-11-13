<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Enums\SlotFrequency;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\MadeRegularNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;

/**
 * Makes someone a regular on a weekly slot (spec section 5) and adds them to the slot's shifts that were already generated. Generation only fills regulars into new shifts, so without this a new regular would be missing from the next few weeks.
 *
 * The actor is who made the change, or null when the system did. Adding a regular is logged about the person, with the ids of the shifts they were added to.
 *
 * A regular can work every week or, on a weekly slot, every other week from an anchor date. People needed is checked date by date, so two people alternating weeks don't count against each other. Going over it is allowed only when `$overConfirmed` is true; without it NeedsOverConfirmation is thrown with the dates (see overDates()).
 *
 * Shifts that have started, are cancelled, or that this person is already on (as a shadow or for that one date) are left alone, and so are dates where the person is on another active shift at an overlapping time. Each shift the person is put on gets its own assignment.added entry, with the shift as the subject, the same as PutOnShift.
 *
 * The returned regular carries an `added_to_shifts` attribute with the number of existing shifts the person was put on, and a `skipped_overlaps` attribute listing the dates (Y-m-d) left out because they overlap another shift the person is on.
 *
 * A person who acts (not the system) has to be able to manage the slot's area. Rotation slots have no regulars, and a person who has been turned off can't be made one. The refusals are written to be shown on screen. The new regular is emailed after the response.
 */
class AddRegular
{
    public function handle(
        ?User $actor,
        WeeklySlot $slot,
        User $user,
        string $startsOn,
        ?string $endsOn = null,
        SlotFrequency $frequency = SlotFrequency::Weekly,
        ?string $anchorDate = null,
        bool $overConfirmed = false,
    ): SlotRegular {
        if ($actor !== null) {
            Gate::forUser($actor)->authorize('manageSchedule', $slot->area);
        }

        if ($slot->is_rotation) {
            throw new DomainException('A rotation has no regulars. Volunteers sign up for it one date at a time.');
        }

        if (! $user->isActive()) {
            throw new DomainException($user->firstName().' has been turned off and can\'t be made a regular.');
        }

        $startsOn = CarbonImmutable::parse($startsOn)->toDateString();
        $endsOn = $endsOn === null ? null : CarbonImmutable::parse($endsOn)->toDateString();
        // Only every-other-week regulars count weeks from an anchor.
        $anchorDate = $anchorDate === null || $frequency === SlotFrequency::Weekly ? null : CarbonImmutable::parse($anchorDate)->toDateString();

        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new InvalidArgumentException('The end date has to be on or after the start date.');
        }

        $regular = new SlotRegular([
            'user_id' => $user->id,
            'frequency' => $frequency,
            'anchor_date' => $anchorDate,
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
        ]);
        $regular->weeklySlot()->associate($slot);
        $regular->ensureValid();

        $added = DB::transaction(function () use ($actor, $slot, $user, $regular, $startsOn, $endsOn, $overConfirmed) {
            $overlapping = $slot->regulars()
                ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn))
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
                ->lockForUpdate()
                ->get();

            if ($overlapping->contains('user_id', $user->id)) {
                throw new DomainException('This person is already a regular on this slot for some of those dates.');
            }

            $overDates = $overConfirmed ? [] : $this->overDates($slot, $regular, $overlapping);

            if ($overDates !== []) {
                throw new NeedsOverConfirmation($overDates);
            }

            $changes = ChangeLogEntry::changesOf($regular);
            $regular->save();

            // Locked last, after the regulars and in date order, keeping the area, slot, shifts order the other scheduling actions use, so nobody is put on or cancels one of these shifts between checking it and filling it. The checks below run on the locked rows.
            $candidates = $slot->shifts()
                ->where('local_date', '>=', $startsOn)
                ->when($endsOn !== null, fn ($query) => $query->where('local_date', '<=', $endsOn))
                ->where('starts_at', '>', CarbonImmutable::now())
                ->orderBy('local_date')
                ->lockForUpdate()
                ->get();
            $assignments = Assignment::query()->whereIn('shift_id', $candidates->modelKeys())->lockForUpdate()->get()->groupBy('shift_id');

            $shifts = new Collection;
            $skippedOverlaps = [];

            foreach ($candidates as $shift) {
                $on = $assignments->get($shift->id, new Collection);
                $date = $shift->local_date->toDateString();

                if ($shift->cancelled_at !== null || ! $regular->activeOn($date) || $on->contains('user_id', $user->id)) {
                    continue;
                }

                if (PutOnShift::overlappingShift($user, $shift) !== null) {
                    $skippedOverlaps[] = $date;

                    continue;
                }

                $assignment = new Assignment(['shift_id' => $shift->id, 'user_id' => $user->id, 'type' => AssignmentType::Regular]);
                $assignmentChanges = ChangeLogEntry::changesOf($assignment);
                $assignment->save();
                // The same entry PutOnShift records, so a shift's history shows everyone put on it however they got there.
                ChangeLogEntry::record($actor, 'assignment.added', $shift, ['assignment_id' => $assignment->id, 'type' => AssignmentType::Regular->value], $assignmentChanges, about: $user);
                $shifts->push($shift);
            }

            ChangeLogEntry::record($actor, 'regular.added', $regular, [
                'weekly_slot_id' => $slot->id,
                'added_to_shift_ids' => $shifts->pluck('id')->values()->all(),
            ], $changes, about: $user);

            return [$regular, $shifts->count(), $skippedOverlaps];
        });

        [$regular, $count, $skippedOverlaps] = $added;
        // Not stored: how many existing shifts the person was added to, and the dates they weren't because of another shift, for the screen's confirmation.
        $regular->setAttribute('added_to_shifts', $count);
        $regular->setAttribute('skipped_overlaps', $skippedOverlaps);

        // After the transaction commits and after the response, so a mail failure never shows an error for a change that was saved.
        defer(fn () => $user->notify(new MadeRegularNotification($slot->loadMissing('area'), $regular, $count)));

        return $regular;
    }

    /**
     * The dates the new regular would work on where the slot's regulars that day would then be more than the people needed, in date order, each with that count and the number needed. Read-only; the screens use it to ask for a confirmation, and handle() refuses with it until the add is confirmed. After the last start or end date among the slot and these regulars, who works when repeats every two weeks, so checking two weeks past it covers every later date; the dates of the slot's shifts that are already generated are checked too, so the list covers every shift the regular would be put on.
     *
     * @param  Collection<int, SlotRegular>|null  $overlapping  The slot's regulars whose dates overlap the new one, when the caller already has them locked.
     * @return list<array{date: string, people: int, needed: int}>
     */
    public function overDates(WeeklySlot $slot, SlotRegular $regular, ?Collection $overlapping = null): array
    {
        $startsOn = $regular->starts_on->toDateString();
        $endsOn = $regular->ends_on?->toDateString();

        $overlapping ??= $slot->regulars()
            ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn))
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
            ->get();

        $lastChange = $overlapping
            ->flatMap(fn (SlotRegular $other) => [$other->starts_on, $other->ends_on])
            ->push($regular->starts_on, $slot->valid_from, $slot->valid_until)
            ->filter()
            ->map(fn (CarbonImmutable $date) => $date->toDateString())
            ->max();

        $lastDate = CarbonImmutable::parse($lastChange, 'UTC')->addDays(14)->toDateString();
        $lastShift = $slot->shifts()->max('local_date');

        if ($lastShift !== null && substr((string) $lastShift, 0, 10) > $lastDate) {
            $lastDate = substr((string) $lastShift, 0, 10);
        }

        if ($endsOn !== null && $endsOn < $lastDate) {
            $lastDate = $endsOn;
        }

        $over = [];

        for ($day = CarbonImmutable::parse($startsOn, 'UTC'); $day->toDateString() <= $lastDate; $day = $day->addDay()) {
            $date = $day->toDateString();

            if (! $slot->occursOn($date) || ! $regular->activeOn($date)) {
                continue;
            }

            $people = $overlapping->filter(fn (SlotRegular $other) => $other->activeOn($date))->count() + 1;

            if ($people > $slot->people_needed) {
                $over[] = ['date' => $date, 'people' => $people, 'needed' => $slot->people_needed];
            }
        }

        return $over;
    }
}
