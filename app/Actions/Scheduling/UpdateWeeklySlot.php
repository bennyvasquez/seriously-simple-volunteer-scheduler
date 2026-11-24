<?php

namespace App\Actions\Scheduling;

use App\Calendar\TimeFormat;
use App\Cover\CoverFill;
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
use App\Notifications\ShiftChangedNotification;
use App\Notifications\ShiftTimeChangedNotification;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Saves changes to a weekly slot and brings its future shifts up to date, as far as that is safe without change notices.
 *
 * Future shifts that nobody edited on their own get the slot's new times, people needed, shadow spots and title. If the slot no longer runs on a shift's date (for example the first or last date moved), a shift nobody is on is cancelled with the reason "The weekly slot changed", without emailing anyone since nobody is on it. A shift people are on gets the slot's details only when StaffedShiftChecks finds no problem (too few shadow spots for the shadows on it, or a time that double-books someone on it); otherwise it keeps its old details. After the response, each person on a changed shift who can sign in gets one email: when a time changed, a change notice is recorded for each shift (SendChangeNotices) and they get ShiftTimeChangedNotification asking whether the new time works, otherwise one ShiftChangedNotification listing every shift of theirs whose title changed; a change to people needed or shadow spots alone emails nobody. A date the slot runs on again whose shift a slot change cancelled (going by the change log, not the reason text), with nobody on it and no closure, is brought back with the slot's details and its current regulars, who fill any cover request still open on it (the releaser hears who is covering). The generator then adds any new dates. Shifts edited on their own, shifts people are on whose date the slot no longer runs on, and shifts that failed a check keep their old details and are returned as kept with the reason, when they differ from the slot or are no longer on its dates. Shifts that have started, and shifts cancelled for any other reason, are never touched.
 *
 * Changing the day or how often the slot repeats (including the first date of an every-other-week slot, which sets its weeks) is refused while the slot has current or future regulars or a future shift someone is on, since that changes when they work. Switching to a rotation is refused while it has current or future regulars. The refusals are written to be shown on screen.
 *
 * Every shift this cancels, brings back or changes gets its own change log entry, and the slot gets one too, all in the same transaction. The area doesn't change. SlotAttributes checks the values.
 */
class UpdateWeeklySlot
{
    public const CANCEL_REASON = 'The weekly slot changed';

    public const KEPT_EDITED = 'Changed on its own';

    public const KEPT_OFF_SCHEDULE = 'The slot doesn\'t run on this date any more';

    public function __construct(private GenerateShifts $generator, private SendChangeNotices $notices) {}

    public function handle(User $actor, WeeklySlot $slot, array $attributes): UpdateResult
    {
        $slot->loadMissing('area');
        Gate::forUser($actor)->authorize('manageSchedule', $slot->area);

        $organization = Organization::current();
        // Each person who can sign in, keyed by id, with the changes to put in their one email.
        $emails = [];
        // The "is covering" emails for cover requests filled by regulars on dates brought back.
        $covered = [];
        // The time change emails, one per person, from SendChangeNotices.
        $timeNotices = [];

        $result = DB::transaction(function () use ($actor, $organization, $slot, $attributes, &$emails, &$covered, &$timeNotices) {
            // The area row first, the same order CreateClosure and the generator use, so a slot save and a closure can't deadlock on the shifts.
            Area::query()->whereKey($slot->area_id)->lockForUpdate()->first();
            // Then the slot, so two saves of the same slot can't interleave.
            $locked = WeeklySlot::query()->whereKey($slot->id)->lockForUpdate()->firstOrFail();
            // Then the slot's regulars, before any shift: the order every scheduling action locks in. Bringing dates back and the generator read them again later, and those reads land on rows this transaction already holds, so nothing locks a regular after a shift. Holding the slot also keeps a new regular from being added meanwhile.
            $regulars = SlotRegular::query()->where('weekly_slot_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $values = SlotAttributes::validate($attributes, $locked);
            $locked->fill($values);
            $this->refuseWhatChangesWhenPeopleWork($actor, $locked, $organization);

            $changes = ChangeLogEntry::changesOf($locked);
            $locked->save();

            $timezone = $organization->timezone;
            $via = ['via' => 'slot.updated', 'weekly_slot_id' => $locked->id];

            // Locked so nobody is put on a shift between reading its people and changing it.
            $shifts = $locked->shifts()->whereNull('cancelled_at')->where('starts_at', '>', now())
                ->orderBy('starts_at')->orderBy('id')->lockForUpdate()->get();
            // Who is on each shift, read with a lock after the shift locks, so someone put on a shift while this save waited for it is seen here and emailed, not missed by an earlier snapshot.
            $assignments = Assignment::query()->with('user')->whereIn('shift_id', $shifts->modelKeys())
                ->orderBy('id')->lockForUpdate()->get()->groupBy('shift_id');

            $updated = [];
            // An Eloquent collection, so contains() matches a shift by its id.
            $kept = new EloquentCollection;
            $keptReasons = [];
            $cancelled = [];
            $timeChanges = [];

            foreach ($shifts as $shift) {
                $date = $shift->local_date->toDateString();
                $runs = $locked->occursOn($date);
                $people = $assignments->get($shift->id, collect())->pluck('user');

                if ($shift->edited_at !== null || ($people->isNotEmpty() && ! $runs)) {
                    if (! $runs || $this->differs($shift, $locked, $date, $timezone)) {
                        $kept->push($shift);
                        $keptReasons[$shift->id] = $runs ? self::KEPT_EDITED : self::KEPT_OFF_SCHEDULE;
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

                $was = TimeFormat::range($shift->starts_at->setTimezone($timezone), $shift->ends_at->setTimezone($timezone));
                $wasTitle = $shift->title;
                $wasStartsAt = $shift->starts_at;
                $wasEndsAt = $shift->ends_at;
                $this->applySlot($shift, $locked, $date, $timezone);

                if (! $shift->isDirty()) {
                    continue;
                }

                if ($people->isNotEmpty()) {
                    // Reads the people on it again with a lock, on rows this transaction already holds.
                    $problem = StaffedShiftChecks::problem($actor, $shift);

                    if ($problem !== null) {
                        $shift->setRawAttributes($shift->getRawOriginal(), true);
                        $kept->push($shift);
                        $keptReasons[$shift->id] = $problem['message'];

                        continue;
                    }
                }

                $notify = $people->isNotEmpty() && $shift->isDirty(['starts_at', 'ends_at', 'title']);
                $timeChanged = $people->isNotEmpty() && $shift->isDirty(['starts_at', 'ends_at']);
                $this->saveAndLog($actor, $shift, 'shift.updated', $via);
                $updated[] = $shift->id;

                if ($notify) {
                    $shift->setRelation('area', $slot->area);

                    if ($timeChanged) {
                        $timeChanges[] = ['shift' => $shift, 'was_starts_at' => $wasStartsAt, 'was_ends_at' => $wasEndsAt, 'was_title' => $wasTitle];
                    }

                    // A shift whose time changed goes in the time email, with its title change on its line.
                    foreach ($timeChanged ? [] : $people->filter(fn (User $person) => $person->canSignIn()) as $person) {
                        $emails[$person->id] ??= ['person' => $person, 'changes' => []];
                        $emails[$person->id]['changes'][] = ['shift' => $shift, 'was' => $was, 'wasTitle' => $wasTitle];
                    }
                }
            }

            // After the shift and assignment locks above. A person with a time change gets the one time email, which also carries the title-only changes to their other shifts, not a plain email as well.
            $timeNotices = $this->notices->recordLocked($actor, $timeChanges);

            foreach ($timeNotices as $sent) {
                $sent['notification']->withTitleChanges(collect($emails[$sent['user']->id]['changes'] ?? []));
                unset($emails[$sent['user']->id]);
            }

            $updated = [...$updated, ...$this->revive($actor, $locked, $regulars, $organization, $cancelled, $via, $covered)];
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

            return new UpdateResult(count($updated), $kept, count($cancelled), $created, $keptReasons, count($emails) + count($timeNotices));
        });

        if ($emails !== []) {
            defer(function () use ($emails) {
                foreach ($emails as $email) {
                    $email['person']->notify(new ShiftChangedNotification(collect($email['changes'])));
                }
            });
        }

        if ($timeNotices !== []) {
            defer(function () use ($timeNotices) {
                foreach ($timeNotices as $sent) {
                    $sent['user']->notify($sent['notification']);
                }
            });
        }

        CoverFill::sendAfterCommit($covered);

        return $result;
    }

    /** Refuses a change to when the slot runs while people work it, and a switch to a rotation while it has regulars. */
    private function refuseWhatChangesWhenPeopleWork(User $actor, WeeklySlot $slot, Organization $organization): void
    {
        $today = $organization->today()->toDateString();
        $regulars = $slot->regulars()->with('user')
            ->currentOrFuture($today)
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
     * The slot's current regulars for that date (the rows handle() locked) whose accounts are still on, checked with a shared lock on each person, are all put back on it, the same way generation fills a new shift: without emails, and listed in the shift's change log entry as added_regular_ids. They fill the shift's open cover requests, oldest first, through CoverFill::fillForRegularsLocked(), and the releasers' emails are added to `$covered` for handle() to send after the commit.
     *
     * @param  EloquentCollection<int, SlotRegular>  $regulars  The slot's regulars, locked by handle().
     * @param  list<int>  $justCancelled
     * @param  list<array{0: User, 1: Notification}>  $covered
     * @return list<int> The ids brought back.
     */
    private function revive(User $actor, WeeklySlot $slot, EloquentCollection $regulars, Organization $organization, array $justCancelled, array $via, array &$covered): array
    {
        $candidates = $slot->shifts()->whereNotNull('cancelled_at')
            ->whereNull('area_closure_id')->whereDoesntHave('assignments')
            ->where('local_date', '>=', $organization->today()->toDateString())->whereNotIn('id', $justCancelled)
            ->orderBy('local_date')->lockForUpdate()->get();

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
            // People taken off this date on purpose stay off it, the same as RestoreShift::regularsJoining(). Read without a lock: removals from this shift hold the shift lock this transaction holds.
            $takenOff = ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.removed')->whereNotNull('affected_user_id')->pluck('affected_user_id');
            // Each person is read with a shared lock (the only lock a user row may take after a shift row), so a turn-off that committed after this transaction's first read is seen, and one still in progress waits for this save, whose release then finds the shift.
            $working = $regulars
                ->filter(fn (SlotRegular $regular) => $regular->activeOn($date) && ! $takenOff->contains($regular->user_id))
                ->map(fn (SlotRegular $regular) => User::query()->whereKey($regular->user_id)->sharedLock()->first())
                ->filter(fn (?User $person) => $person?->isActive() ?? false);

            foreach ($working as $person) {
                $shift->assignments()->create(['user_id' => $person->id, 'type' => AssignmentType::Regular]);
                $added[] = $person->id;
            }

            ChangeLogEntry::record($actor, 'shift.updated', $shift, $via + ['added_regular_ids' => $added], $changes);

            // Nobody was on it (candidates have no assignments), so there are no shadows to tell.
            if ($working->isNotEmpty()) {
                array_push($covered, ...CoverFill::fillForRegularsLocked($actor, $shift, $working->values(), false));
            }
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
            'people_needed' => $slot->people_needed,
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
