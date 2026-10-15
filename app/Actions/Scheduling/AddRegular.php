<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Makes someone a regular on a weekly slot (spec section 5) and adds them to the slot's shifts that were already generated. Generation only fills regulars into new shifts, so without this a new regular would be missing from the next few weeks.
 *
 * Shifts that have started, or that someone else already filled, are left alone.
 */
class AddRegular
{
    public function handle(WeeklySlot $slot, User $user, string $startsOn, ?string $endsOn = null): SlotRegular
    {
        if ($endsOn !== null && $endsOn < $startsOn) {
            throw new InvalidArgumentException('The end date has to be on or after the start date.');
        }

        return DB::transaction(function () use ($slot, $user, $startsOn, $endsOn) {
            $overlapping = $slot->regulars()
                ->when($endsOn !== null, fn ($query) => $query->where('starts_on', '<=', $endsOn))
                ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $startsOn))
                ->lockForUpdate()
                ->get();

            if ($overlapping->contains('user_id', $user->id)) {
                throw new DomainException('This person is already a regular on this slot for some of those dates.');
            }

            if ($overlapping->count() >= $slot->headcount) {
                throw new DomainException('This slot already has as many regulars as it needs for some of those dates.');
            }

            $regular = $slot->regulars()->create([
                'user_id' => $user->id,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
            ]);

            $shifts = $slot->shifts()
                ->where('local_date', '>=', $startsOn)
                ->when($endsOn !== null, fn ($query) => $query->where('local_date', '<=', $endsOn))
                ->where('starts_at', '>', CarbonImmutable::now())
                ->withCount(['assignments as filled' => fn ($query) => $query->where('type', AssignmentType::Regular)])
                ->get()
                ->filter(fn (Shift $shift) => $shift->filled < $shift->headcount);

            foreach ($shifts as $shift) {
                $shift->assignments()->create([
                    'user_id' => $user->id,
                    'type' => AssignmentType::Regular,
                ]);
            }

            return $regular;
        });
    }
}
