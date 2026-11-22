<?php

namespace App\Actions\Scheduling;

use App\Cover\CoverEligibility;
use App\Cover\CoverFill;
use App\Enums\AssignmentType;
use App\Enums\SlotFrequency;
use App\Enums\TakeHow;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\CoverRequest;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftTakenNotification;
use Carbon\CarbonImmutable;
use Closure;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A volunteer takes an open shift (spec section 8.1), whether it's a cover request or any other open place (decision 15). The first person to take the last open place gets it; anyone after them gets ShiftAlreadyTaken ("Someone already took this one."). Other refusals come from CoverEligibility::refusal(), checked under the locks, so someone who just took it in another tab hears "You're already on this shift." The releaser can take their own shift back while it's open (decision 7).
 *
 * "Just this date" locks the area, then the shift, reads the person with a shared lock (as PutOnShift does), adds the assignment (assignment.added with `via` "take", about the person) and fills the shift's oldest open cover request through CoverFill::fillLocked(), which locks the requests last. "Every week" and "every other week" make the person the regular from that date through AddRegular's self-claim, which locks the area, the slot, its regulars and its shifts, and fill the oldest open cover request on every date they're now on, inside the same transaction. Dates AddRegular leaves out (another shift then, or already full) are listed in the person's email.
 *
 * After the commit and the response, the person gets one email ("You're on Front desk, Thursday, October 22"), and CoverFill::notices() tells the releaser, the shadows and the area's leads, each once.
 */
class TakeShift
{
    public const TURNED_OFF = 'Your account has been turned off, so you can\'t take shifts.';

    public function __construct(private AddRegular $addRegular) {}

    /**
     * How this shift can be taken: just this date, plus every week and every other week when it belongs to a weekly slot that isn't a rotation and has no regular on or after its date (decision 15). Every other week is offered only on a slot that repeats every week.
     *
     * @return list<TakeHow>
     */
    public function choices(Shift $shift): array
    {
        $slot = $shift->weekly_slot_id === null ? null : $shift->weeklySlot;

        if ($slot === null || $slot->is_rotation || $slot->regulars()->currentOrFuture($shift->local_date->toDateString())->exists()) {
            return [TakeHow::JustThisDate];
        }

        return $slot->frequency === SlotFrequency::Weekly
            ? [TakeHow::JustThisDate, TakeHow::EveryWeek, TakeHow::EveryOtherWeek]
            : [TakeHow::JustThisDate, TakeHow::EveryWeek];
    }

    /** Returns the person's new assignment on this shift. */
    public function handle(User $person, Shift $shift, TakeHow $how): Assignment
    {
        Gate::forUser($person)->authorize('take', $shift);

        return $how === TakeHow::JustThisDate ? $this->justThisDate($person, $shift) : $this->asRegular($person, $shift, $how);
    }

    private function justThisDate(User $person, Shift $shift): Assignment
    {
        [$assignment, $locked, $current, $request, $others] = DB::transaction(function () use ($person, $shift) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $locked = Shift::query()->with('area')->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            // Read again after the shift lock, so a turn-off that committed since the request loaded the person is seen. A shared lock, never a lock for update, because a user row is never locked for update after a shift row.
            $current = User::query()->whereKey($person->id)->sharedLock()->first();

            if ($current === null || ! $current->isActive()) {
                throw new DomainException(self::TURNED_OFF);
            }

            $refusal = CoverEligibility::refusal($current, $locked);

            if ($refusal !== null) {
                throw new DomainException($refusal);
            }

            if ($locked->openPlaces() === 0) {
                throw new ShiftAlreadyTaken;
            }

            $nobodyButShadowsBefore = $locked->filledCount() === 0;

            $assignment = new Assignment(['shift_id' => $locked->id, 'user_id' => $current->id, 'type' => AssignmentType::Regular]);
            $changes = ChangeLogEntry::changesOf($assignment);
            $assignment->save();

            ChangeLogEntry::record($current, 'assignment.added', $locked, ['assignment_id' => $assignment->id, 'type' => AssignmentType::Regular->value, 'via' => 'take'], $changes, about: $current);

            $request = null;
            $others = CoverFill::fillLocked($current, $locked, $current, $nobodyButShadowsBefore, null, $request)
                ?? CoverFill::sender(CoverFill::notices($current, $locked, $current, $nobodyButShadowsBefore, null));

            return [$assignment, $locked, $current, $request, $others];
        });

        $this->sendAfterCommit($current, $locked, $request, $others);

        return $assignment;
    }

    private function asRegular(User $person, Shift $shift, TakeHow $how): Assignment
    {
        // A first answer without locks, so the usual refusals read the same as for one date. The date is checked again under AddRegular's locks below.
        $refusal = CoverEligibility::refusal($person, $shift);

        if ($refusal !== null) {
            throw new DomainException($refusal);
        }

        if (! in_array($how, $this->choices($shift), true)) {
            throw new DomainException('You can take this date only.');
        }

        $slot = $shift->weeklySlot;
        $date = $shift->local_date->toDateString();
        $regularHow = ($how === TakeHow::EveryOtherWeek ? 'every other ' : '').$slot->weekdayName();
        $taken = null;

        $regular = $this->addRegular->handle(
            $person, $slot, $person, $date,
            frequency: $how === TakeHow::EveryOtherWeek ? SlotFrequency::EveryOtherWeek : SlotFrequency::Weekly,
            anchorDate: $date,
            selfClaim: true,
            onPutOn: function (Collection $putOn, Collection $candidates) use ($person, $shift, $regularHow, &$taken) {
                $locked = $putOn->firstWhere('id', $shift->id);

                if ($locked === null) {
                    // AddRegular left the date out: it's cancelled, has started, or the person is on it or on an overlapping shift. A started shift isn't among the locked candidates; it's read without a lock, since locking it now would break the date order and this only words the refusal.
                    $again = $candidates->firstWhere('id', $shift->id) ?? Shift::query()->find($shift->id);

                    throw new DomainException(($again === null ? null : CoverEligibility::refusal($person, $again)) ?? 'You can take this date only.');
                }

                // Every date the person is now on fills its oldest open request, so a slot someone gave up stops asking for cover once someone takes it every week. The leads hear once, about the date taken; the releaser and shadows get one email each listing their dates. The shifts come in date order, so the date taken is first.
                $sends = [];
                $request = null;

                foreach ($putOn as $putShift) {
                    $putShift->loadMissing('area');
                    // AddRegular has just put the person on it, so they're the only one filling a place when nobody but shadows was on it before.
                    $nobodyButShadowsBefore = $putShift->filledCount() === 1;
                    $filled = CoverFill::fillOldestLocked($person, $putShift, $person);
                    $isTaken = $putShift->id === $shift->id;

                    if ($isTaken) {
                        $request = $filled;
                    } elseif ($filled === null) {
                        continue;
                    }

                    array_push($sends, ...CoverFill::notices($person, $putShift, $person, $nobodyButShadowsBefore, $filled?->releasedBy, $regularHow, tellLeads: $isTaken));
                }

                $assignment = Assignment::query()->where('shift_id', $locked->id)->where('user_id', $person->id)->sole();

                $taken = [$assignment, $locked, $request, CoverFill::sender(CoverFill::merged($sends))];
            },
        );

        [$assignment, $locked, $request, $others] = $taken;
        $dayText = fn (string $day) => CarbonImmutable::parse($day, 'UTC')->format('l, F j');

        $this->sendAfterCommit($person, $locked, $request, $others, $regularHow, array_map($dayText, $regular->getAttribute('skipped_overlaps')), array_map($dayText, $regular->getAttribute('skipped_full')));

        return $assignment;
    }

    /**
     * The person's email and everyone else's, after the response.
     *
     * @param  list<string>  $skippedDates  Left out because the person is on another shift then.
     * @param  list<string>  $fullDates  Left out because they already have the people they need.
     */
    private function sendAfterCommit(User $person, Shift $locked, ?CoverRequest $request, Closure $others, ?string $regularHow = null, array $skippedDates = [], array $fullDates = []): void
    {
        $shadows = collect(CoverFill::shadows($locked))->map(fn (User $shadow) => $shadow->firstName())->all();
        $notification = new ShiftTakenNotification(
            $locked,
            $request?->releasedBy?->firstName(),
            $request?->note,
            $shadows,
            $request !== null && $request->released_by === $person->id,
            $regularHow,
            $skippedDates,
            $fullDates,
        );

        // CoverFill::sendEach() reports a failed email and carries on, so the person's own email failing doesn't stop the others.
        defer(function () use ($person, $notification, $others) {
            CoverFill::sendEach([[$person, $notification]]);
            $others();
        });
    }
}
