<?php

namespace App\Actions\Scheduling;

use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Illuminate\Support\Facades\DB;

/**
 * Clears a person's future schedule when they're turned off or removed (spec section 13, "People leaving"): every current or future regular gets an end date, and they're removed from every shift that hasn't started, cancelled or not, in every area. Shifts that have started keep them, so their history stays. Nobody is emailed: the person is leaving, and the people who run the schedule are the ones who asked for it.
 *
 * A regular's end date is yesterday, or the day before its start when that's later, so a future regular never works a date (SlotRegular::currentOrFuture() and activeOn() treat it that way). A regular on a slot that has already ended is left alone. Each end is logged as `regular.ended` about the person and each removal by RemoveFromShift::removeLocked(), both with `via` saying why (`user.deactivated` or `user.removed`).
 *
 * The candidates are found before the transaction, so no read inside it fixes the snapshot ahead of the locks. Locks are taken in the order every scheduling action uses: the areas in id order, then the slots in id order, then the regular rows, then the shifts by start and id, and the rows acted on are read again under those locks. A shift or slot the person lands on after the candidates are read isn't released here, so each way in checks again under its locks. Turning off commits before the release, and PutOnShift, AddRegular and GenerateShifts read the person's row with a shared lock after their own locks: they refuse (or, for generation, leave the person out) once the turn-off has committed, and a turn-off still in progress waits for them to commit, so the release's candidate read then finds what they added. GenerateShifts also reads the slot's regulars again under the area, slot and regular locks, so a regular ended here isn't filled into a new shift. Removal leaves the person on, so RemovePerson refuses inside its own transaction if anything landed in between.
 */
class ReleaseFutureSchedule
{
    public function __construct(private readonly RemoveFromShift $removeFromShift) {}

    /**
     * @return array{shifts: int, regulars: int} How many shifts they were removed from and how many slots their time as a regular was ended on.
     */
    public function handle(User $actor, User $person, string $via): array
    {
        $today = Organization::current()->today();
        $yesterday = $today->subDay()->toDateString();
        $today = $today->toDateString();
        $regularIds = self::regularsQuery($person, $today)->orderBy('id')->pluck('id');
        $slotIds = SlotRegular::query()->whereKey($regularIds)->distinct()->orderBy('weekly_slot_id')->pluck('weekly_slot_id');
        $shiftIds = self::shiftsQuery($person)->pluck('id');
        $areaIds = WeeklySlot::query()->whereKey($slotIds)->pluck('area_id')
            ->concat(Shift::query()->whereKey($shiftIds)->pluck('area_id'))
            ->unique()->sort()->values();

        if ($regularIds->isEmpty() && $shiftIds->isEmpty()) {
            return ['shifts' => 0, 'regulars' => 0];
        }

        return DB::transaction(function () use ($actor, $person, $via, $today, $yesterday, $regularIds, $slotIds, $shiftIds, $areaIds) {
            Area::query()->whereKey($areaIds)->orderBy('id')->lockForUpdate()->get();
            WeeklySlot::query()->whereKey($slotIds)->orderBy('id')->lockForUpdate()->get();

            // Read under the locks and checked again, so a regular ended or a person removed from a shift meanwhile isn't counted or logged twice. Plain locking reads with no subqueries, so nothing fixes the snapshot before the last lock is taken.
            $regulars = SlotRegular::query()->whereKey($regularIds)->where('user_id', $person->id)->orderBy('id')->lockForUpdate()->get()
                ->filter(fn (SlotRegular $regular) => $regular->ends_on === null || ($regular->ends_on->toDateString() >= $today && $regular->ends_on->greaterThanOrEqualTo($regular->starts_on)))
                ->values();
            $shifts = Shift::query()->whereKey($shiftIds)->where('starts_at', '>', now())->orderBy('starts_at')->orderBy('id')->lockForUpdate()->get();
            $assignments = Assignment::query()->where('user_id', $person->id)->whereIn('shift_id', $shifts->modelKeys())->orderBy('id')->lockForUpdate()->get();
            $assignments->load('user');
            $assignments = $assignments->groupBy('shift_id');
            $shifts = $shifts->filter(fn (Shift $shift) => $assignments->has($shift->id))->values();

            foreach ($regulars as $regular) {
                $endsOn = max($yesterday, $regular->starts_on->subDay()->toDateString());
                // Set as the plain date, so the log holds "2026-10-13" the way the column does, the same as EndRegular.
                $regular->setRawAttributes(['ends_on' => $endsOn] + $regular->getAttributes());
                $changes = ChangeLogEntry::changesOf($regular);
                $regular->save();

                ChangeLogEntry::record($actor, 'regular.ended', $regular, ['weekly_slot_id' => $regular->weekly_slot_id, 'via' => $via], $changes, about: $person);
            }

            foreach ($shifts as $shift) {
                foreach ($assignments[$shift->id] as $assignment) {
                    $this->removeFromShift->removeLocked($actor, $shift, $assignment, ['via' => $via]);
                }
            }

            return ['shifts' => $shifts->count(), 'regulars' => $regulars->pluck('weekly_slot_id')->unique()->count()];
        });
    }

    /**
     * What handle() would do now, for the person page: how many shifts it removes them from and how many slots it ends their time as a regular on. Changes nothing.
     *
     * @return array{shifts: int, regulars: int}
     */
    public static function counts(User $person): array
    {
        return [
            'shifts' => self::shiftsQuery($person)->count(),
            // Slots, not regular rows: a person can have two stints on one slot, and the page says "a regular on {n} slots".
            'regulars' => self::regularsQuery($person, Organization::current()->today()->toDateString())->distinct()->count('weekly_slot_id'),
        ];
    }

    /** The person's regulars that are current or still to start, on slots that haven't ended. */
    private static function regularsQuery(User $person, string $today)
    {
        return SlotRegular::query()
            ->where('user_id', $person->id)
            ->currentOrFuture($today)
            ->whereHas('weeklySlot', fn ($slot) => $slot->where(fn ($valid) => $valid->whereNull('valid_until')->orWhere('valid_until', '>=', $today)));
    }

    /** The shifts the person is on that haven't started, cancelled or not. */
    private static function shiftsQuery(User $person)
    {
        return Shift::query()
            ->where('starts_at', '>', now())
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id));
    }
}
