<?php

namespace App\Actions\Scheduling;

use App\Cover\CoverFill;
use App\Enums\AssignmentType;
use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\MadeRegularNotification;
use Carbon\CarbonImmutable;
use Closure;
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
 * The transaction locks the area, then the slot, then reads the regulars and locks the shifts, the same order EndRegular uses, so the two can't interleave.
 *
 * Shifts that have started, are cancelled, or that this person is already on (as a shadow or for that one date) are left alone, and so are dates where the person is on another active shift at an overlapping time. Each shift the person is put on gets its own assignment.added entry, with the shift as the subject, the same as PutOnShift. Each of those shifts' oldest open cover request is filled through CoverFill::fillForRegularsLocked(), so a slot someone gave up stops asking for cover when a coordinator adds a new regular; the releasers get one "is covering" email each, listing their dates, and the leads aren't told.
 *
 * The returned regular carries an `added_to_shifts` attribute with the number of existing shifts the person was put on, a `skipped_overlaps` attribute listing the dates (Y-m-d) left out because they overlap another shift the person is on, and a `skipped_full` attribute listing the dates a self-claim left out because they already have the people they need (always empty otherwise).
 *
 * A person who acts (not the system) has to be able to manage the slot's area. Rotation slots have no regulars, and a person who has been turned off can't be made one (checked again under the slot lock). The refusals are written to be shown on screen. The new regular is emailed after the response.
 *
 * A volunteer taking a slot for themselves (TakeShift, decision 15) passes `$selfClaim`: the actor has to be the person, the manager check is skipped, the slot must have no regular on or after the start date ("You can take this date only."), and a generated shift that already has the people it needs (counting everyone on it, one-time places included) is left out, the way overlapping dates are, instead of asking to confirm. When that full date is the start date (the one being taken), ShiftAlreadyTaken is thrown instead. overDates() isn't consulted for a self-claim: it counts only regulars, a self-claim is refused whenever the slot has any regular from that date on, and people needed is at least 1, so it could never find a date. Its assignment.added entries carry `via` "take", and no "now a regular" email is sent, since TakeShift sends the person one email for the whole take. `$onPutOn` is called inside the transaction, after everything is logged, with the locked shifts the person was put on and every locked candidate shift, so TakeShift can check the date taken and fill its cover request in the same transaction.
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
        bool $selfClaim = false,
        ?Closure $onPutOn = null,
    ): SlotRegular {
        if ($selfClaim) {
            if ($actor?->id !== $user->id) {
                throw new InvalidArgumentException('Only the person themselves can take a slot this way.');
            }
        } elseif ($actor !== null) {
            Gate::forUser($actor)->authorize('manageSchedule', $slot->area);
        }

        if ($slot->is_rotation) {
            throw new DomainException($selfClaim ? 'You can take this date only.' : 'A rotation has no regulars. Volunteers sign up for it one date at a time.');
        }

        $turnedOff = $selfClaim ? 'Your account has been turned off, so you can\'t take shifts.' : $user->firstName().' has been turned off and can\'t be made a regular.';

        if (! $user->isActive()) {
            throw new DomainException($turnedOff);
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

        $added = DB::transaction(function () use ($actor, $slot, $user, $regular, $startsOn, $endsOn, $overConfirmed, $selfClaim, $onPutOn, $turnedOff) {
            // The area then the slot, before reading the regulars: the order EndRegular and the other scheduling actions lock in.
            Area::query()->whereKey($slot->area_id)->lockForUpdate()->firstOrFail();
            WeeklySlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();

            // Read again under the locks, so a turn-off that committed since the check above is seen. A shared lock: it holds a turn-off back until this commits, and the release that follows it then finds this regular.
            $current = User::query()->whereKey($user->id)->sharedLock()->first();

            if ($current === null || ! $current->isActive()) {
                throw new DomainException($turnedOff);
            }

            // A regular whose end is before its start (ended before it started, or released by a turn-off) never works a date, so it's left out.
            $overlapping = $slot->regulars()
                ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn))
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhere(fn ($dated) => $dated->where('ends_on', '>=', $startsOn)->whereColumn('ends_on', '>=', 'starts_on')))
                ->lockForUpdate()
                ->get();

            // A volunteer can make themselves the regular only on a slot nobody is the regular for from that date on (decision 15).
            if ($selfClaim && $overlapping->isNotEmpty()) {
                throw new DomainException('You can take this date only.');
            }

            if ($overlapping->contains('user_id', $user->id)) {
                throw new DomainException('This person is already a regular on this slot for some of those dates.');
            }

            // A self-claim has no other regulars to count (refused above), so overDates() would always be empty; full dates are left out shift by shift below instead.
            $overDates = $overConfirmed || $selfClaim ? [] : $this->overDates($slot, $regular, $overlapping);

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
            $skippedFull = [];
            $covered = [];

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

                // Someone taking a slot never goes over the people a date needs, counting everyone already on it, one-time places included. The date being taken has to have room; a later full date is left out.
                if ($selfClaim && $on->where('type', AssignmentType::Regular)->count() >= $shift->people_needed) {
                    if ($date === $startsOn) {
                        throw new ShiftAlreadyTaken;
                    }

                    $skippedFull[] = $date;

                    continue;
                }

                $assignment = new Assignment(['shift_id' => $shift->id, 'user_id' => $user->id, 'type' => AssignmentType::Regular]);
                $assignmentChanges = ChangeLogEntry::changesOf($assignment);
                $assignment->save();
                // The same entry PutOnShift records, so a shift's history shows everyone put on it however they got there.
                ChangeLogEntry::record($actor, 'assignment.added', $shift, ['assignment_id' => $assignment->id, 'type' => AssignmentType::Regular->value] + ($selfClaim ? ['via' => 'take'] : []), $assignmentChanges, about: $user);
                $shifts->push($shift);

                // A self-claim fills its requests in TakeShift's $onPutOn, which words the emails for a take.
                if (! $selfClaim) {
                    array_push($covered, ...CoverFill::fillForRegularsLocked($actor, $shift, [$user], $on->where('type', AssignmentType::Regular)->isEmpty()));
                }
            }

            ChangeLogEntry::record($actor, 'regular.added', $regular, [
                'weekly_slot_id' => $slot->id,
                'added_to_shift_ids' => $shifts->pluck('id')->values()->all(),
            ], $changes, about: $user);

            if ($onPutOn !== null) {
                $onPutOn($shifts, $candidates);
            }

            return [$regular, $shifts->count(), $skippedOverlaps, $skippedFull, $covered];
        });

        [$regular, $count, $skippedOverlaps, $skippedFull, $covered] = $added;
        // Not stored: how many existing shifts the person was added to, and the dates they weren't because of another shift or (for a self-claim) because the date was full, for the confirmation and TakeShift's email.
        $regular->setAttribute('added_to_shifts', $count);
        $regular->setAttribute('skipped_overlaps', $skippedOverlaps);
        $regular->setAttribute('skipped_full', $skippedFull);

        // After the transaction commits and after the response, so a mail failure never shows an error for a change that was saved. Someone taking a slot gets TakeShift's email instead.
        if (! $selfClaim) {
            defer(fn () => $user->notify(new MadeRegularNotification($slot->loadMissing('area'), $regular, $count)));
            CoverFill::sendAfterCommit($covered);
        }

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
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere(fn ($dated) => $dated->where('ends_on', '>=', $startsOn)->whereColumn('ends_on', '>=', 'starts_on')))
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
