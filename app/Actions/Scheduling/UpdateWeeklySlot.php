<?php

namespace App\Actions\Scheduling;

use App\Enums\SlotFrequency;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Saves changes to a weekly slot and brings its future shifts up to date, as far as that is safe today.
 *
 * Future shifts that nobody is on and nobody edited by hand get the slot's new times, headcount, shadow spots and title. If the slot no longer runs on a shift's date (the day, how often, the first date or the last date changed), that shift is cancelled with the reason "The weekly slot changed", without emailing anyone since nobody is on it, and the generator then adds the new dates. Shifts someone is on, or that were edited on their own, keep their old details and are returned as kept when they differ from the slot. Shifts that have started or are cancelled are never touched.
 *
 * Cancelled shifts stay on their dates, and a slot has at most one shift per date, so a date cancelled by an earlier change isn't made again if a later change brings that date back. The result counts those dates as stillCancelled.
 *
 * Attribute keys are the same as CreateWeeklySlot's. The area doesn't change. A first date in the past is accepted only when it is unchanged.
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
        $frequency = $attributes['frequency'] instanceof SlotFrequency ? $attributes['frequency'] : SlotFrequency::from($attributes['frequency']);
        $validFrom = CarbonImmutable::parse($attributes['valid_from'])->toDateString();
        $validUntil = ($attributes['valid_until'] ?? null) === null ? null : CarbonImmutable::parse($attributes['valid_until'])->toDateString();
        $title = trim((string) ($attributes['title'] ?? ''));
        $weekday = (int) $attributes['weekday'];

        if ($validFrom !== $slot->valid_from->toDateString() && $validFrom < $organization->today()->toDateString()) {
            throw ValidationException::withMessages(['valid_from' => 'Pick a date from today on.']);
        }

        if ($validUntil !== null && $validUntil < $validFrom) {
            throw ValidationException::withMessages(['valid_until' => 'The last date has to be on or after the first date.']);
        }

        if ($frequency === SlotFrequency::EveryOtherWeek && CarbonImmutable::parse($validFrom, 'UTC')->dayOfWeek !== $weekday) {
            throw ValidationException::withMessages(['valid_from' => 'For every other week, the first date has to fall on the day the slot is for.']);
        }

        if (CarbonImmutable::parse($attributes['start_time'])->format('H:i') === CarbonImmutable::parse($attributes['end_time'])->format('H:i')) {
            throw ValidationException::withMessages(['end_time' => 'A shift has to end at a different time than it starts.']);
        }

        if ((int) $attributes['headcount'] < 1) {
            throw ValidationException::withMessages(['headcount' => 'At least 1 person is needed.']);
        }

        $slot->fill([
            'weekday' => $weekday,
            'start_time' => $attributes['start_time'],
            'end_time' => $attributes['end_time'],
            'headcount' => (int) $attributes['headcount'],
            'shadow_spots' => (int) $attributes['shadow_spots'],
            'frequency' => $frequency,
            'anchor_date' => $frequency === SlotFrequency::EveryOtherWeek ? $validFrom : null,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
            'title' => $title === '' ? null : $title,
            'is_rotation' => (bool) ($attributes['is_rotation'] ?? false),
        ]);

        return DB::transaction(function () use ($actor, $organization, $slot) {
            $changes = ChangeLogEntry::changesOf($slot);
            $slot->save();

            // Locked so nobody is put on a shift between counting its people and changing it.
            $shifts = $slot->shifts()->whereNull('cancelled_at')->where('starts_at', '>', now())
                ->orderBy('local_date')->lockForUpdate()->withCount('assignments')->get();

            $updated = [];
            // An Eloquent collection, so contains() matches a shift by its id.
            $kept = new EloquentCollection;
            $cancelled = [];

            foreach ($shifts as $shift) {
                $date = $shift->local_date->toDateString();
                $runs = $slot->occursOn($date);

                if ($shift->assignments_count > 0 || $shift->edited_at !== null) {
                    if (! $runs || $this->differs($shift, $slot, $date, $organization->timezone)) {
                        $kept->push($shift);
                    }

                    continue;
                }

                if (! $runs) {
                    $shift->cancelled_at = now();
                    $shift->cancel_reason = self::CANCEL_REASON;
                    $shift->save();
                    $cancelled[] = $shift->id;

                    continue;
                }

                $this->applySlot($shift, $slot, $date, $organization->timezone);

                if ($shift->isDirty()) {
                    $shift->save();
                    $updated[] = $shift->id;
                }
            }

            $created = $this->generator->forSlot($organization, $slot);

            $stillCancelled = $slot->shifts()->whereNotNull('cancelled_at')->where('cancel_reason', self::CANCEL_REASON)
                ->where('local_date', '>=', $organization->today()->toDateString())->whereNotIn('id', $cancelled)->get()
                ->filter(fn (Shift $shift) => $slot->occursOn($shift->local_date->toDateString()))->count();

            ChangeLogEntry::record($actor, 'slot.updated', $slot, [
                'area_id' => $slot->area_id,
                'updated_shifts' => count($updated),
                'updated_shift_ids' => $updated,
                'kept_shift_ids' => $kept->pluck('id')->all(),
                'cancelled_shift_ids' => $cancelled,
                'created_shifts' => $created,
            ], $changes);

            return new UpdateResult(count($updated), $kept, count($cancelled), $created, $stillCancelled);
        });
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
