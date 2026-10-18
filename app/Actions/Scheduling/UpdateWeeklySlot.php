<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Saves changes to a weekly slot and brings its future shifts up to date, as far as that is safe without change notices.
 *
 * Future shifts that nobody is on and nobody edited by hand get the slot's new times, headcount, shadow spots and title. If the slot no longer runs on a shift's date (for example the first or last date moved), that shift is cancelled with the reason "The weekly slot changed", without emailing anyone since nobody is on it. A date the slot runs on again whose shift a slot change cancelled (going by the change log, not the reason text), with nobody on it and no closure, is brought back with the slot's details and its current regulars. The generator then adds any new dates. Shifts someone is on, or that were edited on their own, keep their old details and are returned as kept when they differ from the slot or are no longer on its dates. Shifts that have started, and shifts cancelled for any other reason, are never touched.
 *
 * Changing the day or how often the slot repeats (including the first date of an every-other-week slot, which sets its weeks) is refused while the slot has current or future regulars or a future shift someone is on, since that changes when they work. Switching to a rotation is refused while it has current or future regulars. The refusals are written to be shown on screen.
 *
 * Every shift this cancels, brings back or changes gets its own change log entry, and the slot gets one too, all in the same transaction. The area doesn't change. SlotAttributes checks the values.
 */
class UpdateWeeklySlot
{
    public const CANCEL_REASON = 'The weekly slot changed';

    public function __construct(private GenerateShifts $generator) {}

    public function handle(User $actor, WeeklySlot $slot, array $attributes): UpdateResult
    {
        $slot->loadMissing('area');
        Gate::forUser($actor)->authorize('manageSchedule', $slot->area);

        $organization = Organization::current();

        return DB::transaction(function () use ($actor, $organization, $slot, $attributes) {
            // The area row first, the same order CreateClosure and the generator use, so a slot save and a closure can't deadlock on the shifts.
            Area::query()->whereKey($slot->area_id)->lockForUpdate()->first();
            // Then the slot, so two saves of the same slot can't interleave.
            $locked = WeeklySlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();
            $values = SlotAttributes::validate($attributes, $locked);
            $locked->fill($values);
            $this->refuseWhatChangesWhenPeopleWork($actor, $locked, $organization);

            $changes = ChangeLogEntry::changesOf($locked);
            $locked->save();

            $timezone = $organization->timezone;
            $via = ['via' => 'slot.updated', 'weekly_slot_id' => $locked->id];

            // Locked so nobody is put on a shift between counting its people and changing it.
            $shifts = $locked->shifts()->whereNull('cancelled_at')->where('starts_at', '>', now())
                ->orderBy('local_date')->lockForUpdate()->withCount('assignments')->get();

            $updated = [];
            // An Eloquent collection, so contains() matches a shift by its id.
            $kept = new EloquentCollection;
            $cancelled = [];

            foreach ($shifts as $shift) {
                $date = $shift->local_date->toDateString();
                $runs = $locked->occursOn($date);

                if ($shift->assignments_count > 0 || $shift->edited_at !== null) {
                    if (! $runs || $this->differs($shift, $locked, $date, $timezone)) {
                        $kept->push($shift);
                    }

                    continue;
                }

                if (! $runs) {
                    $shift->cancelled_at = now();
                    $shift->cancel_reason = self::CANCEL_REASON;
                    $this->saveAndLog($actor, $shift, 'shift.cancelled', $via);
                    $cancelled[] = $shift->id;

                    continue;
                }

                $this->applySlot($shift, $locked, $date, $timezone);

                if ($shift->isDirty()) {
                    $this->saveAndLog($actor, $shift, 'shift.updated', $via);
                    $updated[] = $shift->id;
                }
            }

            $updated = [...$updated, ...$this->revive($actor, $locked, $organization, $cancelled, $via)];
            $created = $this->generator->forSlot($organization, $locked);

            ChangeLogEntry::record($actor, 'slot.updated', $locked, [
                'area_id' => $locked->area_id,
                'updated_shifts' => count($updated),
                'updated_shift_ids' => $updated,
                'kept_shift_ids' => $kept->pluck('id')->all(),
                'cancelled_shift_ids' => $cancelled,
                'created_shifts' => $created,
            ], $changes);

            $slot->setRawAttributes($locked->getAttributes(), true);

            return new UpdateResult(count($updated), $kept, count($cancelled), $created);
        });
    }

    /** Refuses a change to when the slot runs while people work it, and a switch to a rotation while it has regulars. */
    private function refuseWhatChangesWhenPeopleWork(User $actor, WeeklySlot $slot, Organization $organization): void
    {
        $today = $organization->today()->toDateString();
        $regulars = $slot->regulars()->with('user')
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))
            ->orderBy('id')->get()->pluck('user');

        if ($slot->isDirty(['weekday', 'frequency', 'anchor_date'])) {
            $onShifts = Assignment::query()->with('user')->orderBy('id')
                ->whereHas('shift', fn ($query) => $query->where('weekly_slot_id', $slot->id)->whereNull('cancelled_at')->where('starts_at', '>', now()))
                ->get()->pluck('user');
            $people = $regulars->concat($onShifts)->unique('id')->values();

            if ($people->isNotEmpty()) {
                throw new DomainException($this->names($actor, $people).' '.($people->count() === 1 ? 'works' : 'work').' this slot. Changing its day or how often it repeats changes when they work, and that needs the change notices coming in the next update. For now, set a last date on this slot and add a new one.');
            }
        }

        if ($slot->is_rotation && ! $slot->getOriginal('is_rotation') && $regulars->isNotEmpty()) {
            $people = $regulars->unique('id')->values();

            throw new DomainException($people->count() === 1
                ? $this->names($actor, $people).' is a regular on this slot. Ask an admin to end their time as a regular before making it a rotation.'
                : $this->names($actor, $people).' are regulars on this slot. Ask an admin to end their time as regulars before making it a rotation.');
        }
    }

    /**
     * "Sam", "Sam and Robin", "Sam, Robin and Alex". Full names only for people the actor may see (spec section 13).
     *
     * @param  Collection<int, User>  $people
     */
    private function names(User $actor, Collection $people): string
    {
        return $people->map(fn (User $person) => $actor->canSee($person) ? $person->name : $person->shortName())->join(', ', ' and ');
    }

    /**
     * Brings back dates the slot runs on again whose shift an earlier slot change cancelled, when nobody is on it, no closure covers it, and it hasn't started. A slot change is recognised by the shift's most recent shift.cancelled entry having come from slot.updated, never by the reason text, so a person who typed the same words keeps their cancellation.
     *
     * The slot's current regulars for that date whose accounts are still on are put back on it, up to its headcount, the same way generation fills a new shift: without emails, and listed in the shift's change log entry as added_regular_ids.
     *
     * @param  list<int>  $justCancelled
     * @return list<int> The ids brought back.
     */
    private function revive(User $actor, WeeklySlot $slot, Organization $organization, array $justCancelled, array $via): array
    {
        $candidates = $slot->shifts()->whereNotNull('cancelled_at')
            ->whereNull('area_closure_id')->whereDoesntHave('assignments')
            ->where('local_date', '>=', $organization->today()->toDateString())->whereNotIn('id', $justCancelled)
            ->orderBy('local_date')->lockForUpdate()->get();

        $regulars = $slot->regulars()->with('user')->orderBy('id')->get();
        $revived = [];

        foreach ($candidates as $shift) {
            $date = $shift->local_date->toDateString();

            if (! $slot->occursOn($date) || ! $this->cancelledBySlotChange($shift)
                || AreaClosure::query()->where('area_id', $slot->area_id)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->exists()) {
                continue;
            }

            $this->applySlot($shift, $slot, $date, $organization->timezone);

            if ($shift->starts_at->lessThanOrEqualTo(now())) {
                continue;
            }

            $shift->cancelled_at = null;
            $shift->cancel_reason = null;
            $changes = ChangeLogEntry::changesOf($shift);
            $shift->save();

            $added = [];
            foreach ($regulars->filter(fn (SlotRegular $regular) => $regular->activeOn($date) && $regular->user->isActive())->take($shift->headcount) as $regular) {
                $shift->assignments()->create(['user_id' => $regular->user_id, 'type' => AssignmentType::Regular]);
                $added[] = $regular->user_id;
            }

            ChangeLogEntry::record($actor, 'shift.updated', $shift, $via + ['added_regular_ids' => $added], $changes);
            $revived[] = $shift->id;
        }

        return $revived;
    }

    private function cancelledBySlotChange(Shift $shift): bool
    {
        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.cancelled')->latest('id')->first();

        return ($entry?->details['via'] ?? null) === 'slot.updated';
    }

    private function saveAndLog(User $actor, Shift $shift, string $action, array $via): void
    {
        $changes = ChangeLogEntry::changesOf($shift);
        $shift->save();

        ChangeLogEntry::record($actor, $action, $shift, $via, $changes);
    }

    private function applySlot(Shift $shift, WeeklySlot $slot, string $date, string $timezone): void
    {
        [$startsAt, $endsAt] = $slot->timesOn($date, $timezone);

        $shift->fill([
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'headcount' => $slot->headcount,
            'shadow_spots' => $slot->shadow_spots,
            'title' => $slot->title,
        ]);
    }

    /** Whether a kept shift's details are different from what the slot would give it now. */
    private function differs(Shift $shift, WeeklySlot $slot, string $date, string $timezone): bool
    {
        $copy = $shift->replicate();
        $copy->syncOriginal();
        $this->applySlot($copy, $slot, $date, $timezone);

        return $copy->isDirty();
    }
}
