<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Enums\SlotFrequency;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes someone a regular on a weekly slot (spec section 5) and adds them to the slot's shifts that were already generated. Generation only fills regulars into new shifts, so without this a new regular would be missing from the next few weeks.
 *
 * A regular can work every week or, on a weekly slot, every other week from an anchor date. Headcount is checked date by date, so two people alternating weeks don't count against each other.
 *
 * Shifts that have started, that someone else already filled, or that this person is already on (as a shadow or for that one date) are left alone.
 */
class AddRegular
{
    public function handle(
        WeeklySlot $slot,
        User $user,
        string $startsOn,
        ?string $endsOn = null,
        SlotFrequency $frequency = SlotFrequency::Weekly,
        ?string $anchorDate = null,
    ): SlotRegular {
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

        return DB::transaction(function () use ($slot, $user, $regular, $startsOn, $endsOn) {
            $overlapping = $slot->regulars()
                ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn))
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
                ->lockForUpdate()
                ->get();

            if ($overlapping->contains('user_id', $user->id)) {
                throw new DomainException('This person is already a regular on this slot for some of those dates.');
            }

            $fullOn = $this->firstFullDate($slot, $regular, $overlapping);

            if ($fullOn !== null) {
                throw new DomainException('This slot already has as many regulars as it needs, starting '.CarbonImmutable::parse($fullOn)->format('l j F Y').'.');
            }

            $regular->save();

            $shifts = $slot->shifts()
                ->where('local_date', '>=', $startsOn)
                ->when($endsOn !== null, fn ($query) => $query->where('local_date', '<=', $endsOn))
                ->where('starts_at', '>', CarbonImmutable::now())
                ->whereDoesntHave('assignments', fn ($query) => $query->where('user_id', $user->id))
                ->withCount(['assignments as filled' => fn ($query) => $query->where('type', AssignmentType::Regular)])
                ->get()
                ->filter(fn (Shift $shift) => $shift->filled < $shift->headcount && $regular->activeOn($shift->local_date->toDateString()));

            foreach ($shifts as $shift) {
                $shift->assignments()->create([
                    'user_id' => $user->id,
                    'type' => AssignmentType::Regular,
                ]);
            }

            return $regular;
        });
    }

    /**
     * Returns the first date (Y-m-d) the new regular would work on which the slot already has its full headcount of regulars, or null. After the last start or end date among the slot and these regulars, who works when repeats every two weeks, so checking two weeks past it covers every later date.
     *
     * @param  Collection<int, SlotRegular>  $overlapping
     */
    private function firstFullDate(WeeklySlot $slot, SlotRegular $regular, Collection $overlapping): ?string
    {
        $lastChange = $overlapping
            ->flatMap(fn (SlotRegular $other) => [$other->starts_on, $other->ends_on])
            ->push($regular->starts_on, $slot->valid_from, $slot->valid_until)
            ->filter()
            ->map(fn (CarbonImmutable $date) => $date->toDateString())
            ->max();

        $lastDate = CarbonImmutable::parse($lastChange, 'UTC')->addDays(14)->toDateString();

        if ($regular->ends_on !== null && $regular->ends_on->toDateString() < $lastDate) {
            $lastDate = $regular->ends_on->toDateString();
        }

        for ($day = CarbonImmutable::parse($regular->starts_on->toDateString(), 'UTC'); $day->toDateString() <= $lastDate; $day = $day->addDay()) {
            $date = $day->toDateString();

            if (! $slot->occursOn($date) || ! $regular->activeOn($date)) {
                continue;
            }

            if ($overlapping->filter(fn (SlotRegular $other) => $other->activeOn($date))->count() >= $slot->headcount) {
                return $date;
            }
        }

        return null;
    }
}
