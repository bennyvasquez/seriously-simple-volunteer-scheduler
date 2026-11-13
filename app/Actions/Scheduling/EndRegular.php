<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\RegularEndedNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A coordinator or area lead ends a regular's time on a weekly slot: the regular gets an end date, and the person comes off every shift of the slot after that date, including ones that have started when the date is in the past. Ending in the past needs `$pastConfirmed`, which the screen sets after listing those past shifts (see pastShifts()). Nobody is emailed about past shifts.
 *
 * `$lastDate` is the last date they work. When it's before the regular's start date, the stored end date is the day before the start, so the regular never works a date. Only shifts inside the regular's own dates are touched, so a later stint added for the same person isn't removed, and only the regular's own assignments (type regular) are removed, so a shadow spot stays.
 *
 * Locks are taken area, weekly slot, the regular's row, then the slot's shifts in date order, and the assignments are read under those locks, the same order AddRegular uses. Each removal is logged by RemoveFromShift::removeLocked() and the end is logged as `regular.ended` about the person. The returned regular carries a `removed_from_shifts` attribute with how many shifts they were removed from, and a `before_start` attribute that is true when the last date was before the regular's start. The refusals are written to be shown on screen. The person is emailed after the response, listing only future shifts that weren't cancelled.
 */
class EndRegular
{
    public function __construct(private readonly RemoveFromShift $removeFromShift) {}

    /**
     * The slot's shifts after the last date that have started and that the person is on as a regular, cancelled or not, in date order. Read-only: the screen lists these for the confirmation, and handle() refuses without it.
     *
     * @return Collection<int, Shift>
     */
    public function pastShifts(SlotRegular $regular, string $lastDate): Collection
    {
        return $this->window($regular, $this->boundary($regular, $lastDate))
            ->where('starts_at', '<=', now())
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $regular->user_id)->where('type', AssignmentType::Regular))
            ->orderBy('starts_at')->orderBy('id')
            ->get();
    }

    public function handle(User $actor, SlotRegular $regular, string $lastDate, bool $pastConfirmed = false): SlotRegular
    {
        $slot = $regular->weeklySlot;
        Gate::forUser($actor)->authorize('manageSchedule', $slot->area);

        $lastDate = CarbonImmutable::parse($lastDate, 'UTC')->toDateString();

        $result = DB::transaction(function () use ($actor, $regular, $slot, $lastDate, $pastConfirmed) {
            Area::query()->whereKey($slot->area_id)->lockForUpdate()->firstOrFail();
            WeeklySlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();
            // Read under the locks, so the checks below see the regular as it is now.
            $locked = SlotRegular::query()->with('user')->whereKey($regular->id)->where('weekly_slot_id', $slot->id)->lockForUpdate()->firstOrFail();
            $person = $locked->user;
            $first = $person->firstName();

            $bound = $this->boundary($locked, $lastDate);
            $endsOn = $locked->ends_on?->toDateString();

            if ($endsOn !== null && $bound > $endsOn) {
                throw new DomainException($first.' already stops after '.$this->words($endsOn).'. To extend it, add them as a regular again.');
            }

            if ($endsOn === $bound) {
                throw new DomainException($first.' already stops after '.$this->words($endsOn).'.');
            }

            $shifts = $this->window($locked, $bound)->orderBy('starts_at')->orderBy('id')->lockForUpdate()->get();
            $assignments = Assignment::query()->with('user')->where('user_id', $person->id)->where('type', AssignmentType::Regular)->whereIn('shift_id', $shifts->modelKeys())->lockForUpdate()->get()->keyBy('shift_id');
            $shifts = $shifts->filter(fn (Shift $shift) => $assignments->has($shift->id))->values();
            $past = $shifts->filter(fn (Shift $shift) => $shift->starts_at->lessThanOrEqualTo(now()))->values();

            if ($past->isNotEmpty() && ! $pastConfirmed) {
                throw new DomainException('Confirm the past shifts first.');
            }

            // Set as the plain date, so the log holds "2026-10-29" the way the column does, not a timestamp.
            $locked->setRawAttributes(['ends_on' => $bound] + $locked->getAttributes());
            $changes = ChangeLogEntry::changesOf($locked);
            $locked->save();

            foreach ($shifts as $shift) {
                $this->removeFromShift->removeLocked($actor, $shift, $assignments[$shift->id], ['via' => 'regular.ended', 'slot_regular_id' => $locked->id]);
            }

            ChangeLogEntry::record($actor, 'regular.ended', $locked, [
                'weekly_slot_id' => $slot->id,
                'removed_from_shift_ids' => $shifts->modelKeys(),
                'past_shift_ids' => $past->modelKeys(),
            ], $changes, about: $person);

            return [$locked, $shifts];
        });

        [$ended, $shifts] = $result;
        $ended->setAttribute('removed_from_shifts', $shifts->count());

        // A last date before the start stores the day before the start, so the regular never worked a date.
        $ended->setAttribute('before_start', $lastDate < $ended->starts_on->toDateString());

        $future = $shifts->filter(fn (Shift $shift) => $shift->starts_at->greaterThan(now()) && $shift->cancelled_at === null)->values();
        $beforeStart = $ended->before_start;
        defer(fn () => $ended->user->notify(new RegularEndedNotification($slot->loadMissing('area'), $lastDate, $future, $beforeStart)));

        return $ended;
    }

    /** The slot's shifts after the boundary date and inside the regular's own dates. */
    private function window(SlotRegular $regular, string $bound)
    {
        return Shift::query()
            ->where('weekly_slot_id', $regular->weekly_slot_id)
            ->where('local_date', '>', $bound)
            ->when($regular->ends_on !== null, fn ($query) => $query->where('local_date', '<=', $regular->ends_on->toDateString()));
    }

    /** The date stored as the end: the last date they work, or the day before the start when that's earlier. */
    private function boundary(SlotRegular $regular, string $lastDate): string
    {
        $lastDate = CarbonImmutable::parse($lastDate, 'UTC')->toDateString();
        $beforeStart = $regular->starts_on->subDay()->toDateString();

        return max($lastDate, $beforeStart);
    }

    private function words(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->format('l, F j');
    }
}
