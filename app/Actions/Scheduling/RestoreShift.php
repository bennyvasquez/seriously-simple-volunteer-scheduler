<?php

namespace App\Actions\Scheduling;

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
use App\Notifications\ShiftRestoredNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Brings back a shift that was cancelled by hand, with everyone who was on it. A shift from a weekly slot that nobody edited on its own comes back with the slot's current details and regulars (see restoreLocked()), since slot changes and new regulars skip cancelled shifts. Refuses a shift that isn't cancelled or has started, one a closure or a weekly slot change cancelled (those come back when the closure or the slot is changed), and a date an area closure covers now. Anyone on it who is now on another active shift at an overlapping time is removed from this one, and the status says so. The people still on it who can sign in are emailed after the response: "back on", or, when the slot's time changed while it was cancelled, the change notice email asking whether the new time works (SendChangeNotices, recorded in the same transaction). The refusals are written to be shown on screen. restoreLocked() is the shared step: closure changes call it with the area, the area's weekly slots and their regulars, and the shift already locked, and send any email themselves. preview() says the same without changing anything, for the confirm steps.
 */
class RestoreShift
{
    public const HAND = 'hand';

    public const CLOSURE = 'closure';

    public const SLOT = 'slot';

    public function __construct(private readonly SendChangeNotices $notices) {}

    /** How the shift was cancelled: `closure` when it carries a closure, `slot` when its latest shift.cancelled entry came from a weekly slot change (never judged by the reason text), otherwise `hand`. */
    public static function cancelledBy(Shift $shift): string
    {
        if ($shift->area_closure_id !== null) {
            return self::CLOSURE;
        }

        $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.cancelled')->latest('id')->first();

        return ($entry?->details['via'] ?? null) === 'slot.updated' ? self::SLOT : self::HAND;
    }

    /** Why a hand-cancelled shift can't come back right now, as a sentence for the screen: its weekly slot no longer runs on its date, or a closure covers the date. Null when nothing stands in the way. */
    public static function blockedBy(Shift $shift): ?string
    {
        $date = $shift->local_date->toDateString();
        $slot = $shift->weeklySlot;

        if ($slot !== null && ! $slot->occursOn($date)) {
            return 'The weekly slot doesn\'t run on this date any more. Change the slot to bring it back.';
        }

        $closure = AreaClosure::query()->where('area_id', $shift->area_id)->where('starts_on', '<=', $date)->where('ends_on', '>=', $date)->first();

        return $closure === null ? null : ($shift->area?->name ?? Area::query()->find($shift->area_id)->name).' is closed that day: '.$closure->reason.'.';
    }

    /**
     * Clears the cancellation and catches the shift up with its weekly slot, logs shift.restored with `$via`, and removes each person now on another active shift at an overlapping time. The caller holds the area lock, the lock on the shift's weekly slot and its regulars, and the shift lock, has authorized the actor, checked the shift is cancelled and hasn't started, and sends any email.
     *
     * A shift from a weekly slot whose `edited_at` is null gets the slot's current times for its date, title and people needed, and its shadow spots unless that would be fewer than the shadows on it; a slot time that has already started leaves the times alone. The slot's regulars who work that date, aren't on it and aren't turned off are added as regulars, the same way UpdateWeeklySlot brings back a date. The detail changes go in the shift.restored entry's changes, and the people added in its details as `added_regular_ids`. A shift edited on its own keeps its details and gets nobody new.
     *
     * When the slot's time moved while the shift was cancelled, the shift comes back at the new time and `timeChange` holds its old start and end and the ids of the regulars who joined (null when the time didn't change). The caller passes it to SendChangeNotices::recordLocked() in the same transaction, after this returns (so people removed for an overlap get nothing), and sends the person the time email in place of "back on".
     *
     * Each regular who joined and stays on fills one of the shift's open cover requests, oldest first, through CoverFill::fillForRegularsLocked(). `covered` holds the "is covering" emails for the releasers (and "back on" for shadows), for the caller to send after the commit with CoverFill::sendAfterCommit().
     *
     * @return array{emailed: Collection<int, User>, removed: Collection<int, User>, covered: list<array{0: User, 1: Notification}>, timeChange: ?array{was_starts_at: CarbonImmutable, was_ends_at: CarbonImmutable, except: list<int>}} The people still on the shift who can sign in, the people removed from it, the emails for the cover requests filled, and the old time when it changed.
     */
    public function restoreLocked(User $actor, Shift $lockedShift, array $via): array
    {
        // Locking reads of rows the caller already holds, so nothing locks a slot or a regular after a shift.
        $catchesUp = $lockedShift->weekly_slot_id !== null && $lockedShift->edited_at === null;
        $slot = $catchesUp ? WeeklySlot::query()->whereKey($lockedShift->weekly_slot_id)->lockForUpdate()->first() : null;
        $regulars = $slot === null ? collect() : SlotRegular::query()->where('weekly_slot_id', $slot->id)->orderBy('id')->lockForUpdate()->get();
        $before = Assignment::query()->where('shift_id', $lockedShift->id)->orderBy('id')->lockForUpdate()->get();
        $joining = collect();
        $timeChange = null;

        if ($slot !== null) {
            $wasStartsAt = $lockedShift->starts_at;
            $wasEndsAt = $lockedShift->ends_at;
            self::applySlot($lockedShift, $slot, $before->where('type', AssignmentType::Shadow)->count());

            $timeMoved = $lockedShift->isDirty(['starts_at', 'ends_at']);

            // Each person is read with a shared lock (the only lock a user row may take after a shift row), the same as UpdateWeeklySlot::revive().
            $joining = self::regularsJoining($lockedShift, $regulars, $before, fn (int $id) => User::query()->whereKey($id)->sharedLock()->first());

            // Regulars who join as it comes back never knew the old time, so they aren't asked about it.
            if ($timeMoved) {
                $timeChange = ['was_starts_at' => $wasStartsAt, 'was_ends_at' => $wasEndsAt, 'except' => $joining->modelKeys()];
            }
        }

        $lockedShift->cancelled_at = null;
        $lockedShift->cancel_reason = null;
        $lockedShift->area_closure_id = null;
        $changes = ChangeLogEntry::changesOf($lockedShift);
        $lockedShift->save();

        foreach ($joining as $person) {
            $lockedShift->assignments()->create(['user_id' => $person->id, 'type' => AssignmentType::Regular]);
        }

        $details = ['area_id' => $lockedShift->area_id] + $via + ($joining->isEmpty() ? [] : ['added_regular_ids' => $joining->modelKeys()]);
        ChangeLogEntry::record($actor, 'shift.restored', $lockedShift, $details, $changes);

        // Read after the locks, so the people and their other shifts are the committed ones.
        $assignments = Assignment::query()->with('user')->where('shift_id', $lockedShift->id)->orderBy('id')->lockForUpdate()->get();
        $removed = collect();
        $staying = collect();

        foreach ($assignments as $assignment) {
            if (PutOnShift::overlappingShift($assignment->user, $lockedShift) !== null) {
                app(RemoveFromShift::class)->removeLocked($actor, $lockedShift, $assignment, ['via' => 'shift.restored']);
                $removed->push($assignment->user);
            } else {
                $staying->push($assignment->user);
            }
        }

        // Locked last, after the shift. Only the people who joined and stay on cover a released place.
        $covering = $joining->filter(fn (User $person) => $staying->contains('id', $person->id))->values();
        $covered = $covering->isEmpty() ? [] : CoverFill::fillForRegularsLocked($actor, $lockedShift, $covering, $before->where('type', AssignmentType::Regular)->isEmpty());

        return ['emailed' => $staying->filter(fn (User $user) => $user->canSignIn())->values(), 'removed' => $removed, 'covered' => $covered, 'timeChange' => $timeChange];
    }

    /**
     * What bringing the shift back would do, changing nothing: a copy of the shift with the details it would come back with and everyone who would be on it (assignments.user loaded, people joining as unsaved assignments), the people who would stay and the people who would be removed for an overlap. The confirm steps use it to count who is emailed.
     *
     * @return array{shift: Shift, staying: Collection<int, User>, removed: Collection<int, User>}
     */
    public static function preview(Shift $shift): array
    {
        $copy = clone $shift;
        $assignments = Assignment::query()->with('user')->where('shift_id', $shift->id)->orderBy('id')->get();
        $slot = self::slotFor($shift);

        if ($slot !== null) {
            self::applySlot($copy, $slot, $assignments->where('type', AssignmentType::Shadow)->count());
            $regulars = SlotRegular::query()->where('weekly_slot_id', $slot->id)->orderBy('id')->get();
            $joining = self::regularsJoining($copy, $regulars, $assignments, fn (int $id) => User::query()->find($id));

            foreach ($joining as $person) {
                $assignments->push((new Assignment(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => AssignmentType::Regular]))->setRelation('user', $person));
            }
        }

        $copy->setRelation('assignments', $assignments);
        [$removed, $staying] = $assignments->pluck('user')->partition(fn (User $person) => PutOnShift::overlappingShift($person, $copy) !== null);

        return ['shift' => $copy, 'staying' => $staying->values(), 'removed' => $removed->values()];
    }

    /** The weekly slot a shift catches up with when it comes back: none for a one-time shift or one edited on its own. */
    private static function slotFor(Shift $shift): ?WeeklySlot
    {
        return $shift->weekly_slot_id === null || $shift->edited_at !== null ? null : WeeklySlot::query()->find($shift->weekly_slot_id);
    }

    /** Puts the slot's current details for the shift's date on the shift, without saving. Keeps the shift's own shadow spots when the slot's are fewer than the shadows on it, and its own times when the slot's time that day has started. */
    private static function applySlot(Shift $shift, WeeklySlot $slot, int $shadows): void
    {
        [$startsAt, $endsAt] = $slot->timesOn($shift->local_date->toDateString(), Organization::current()->timezone);

        if ($startsAt->greaterThan(now())) {
            $shift->fill(['starts_at' => $startsAt, 'ends_at' => $endsAt]);
        }

        $shift->fill([
            'people_needed' => $slot->people_needed,
            'shadow_spots' => $slot->shadow_spots < $shadows ? $shift->shadow_spots : $slot->shadow_spots,
            'title' => $slot->title,
        ]);
    }

    /**
     * The slot's regulars who work the shift's date, aren't on it already, weren't taken off it and aren't turned off, the earliest added first.
     *
     * Anyone with an assignment.removed entry for this shift was taken off it on purpose (by a coordinator, or for an overlap), so bringing the shift back leaves them off. The entries are read without a lock: every removal from this shift holds the shift lock the caller holds, and a locking read here would take gap locks on the change log that block other actions' entries.
     *
     * @param  Collection<int, SlotRegular>  $regulars
     * @param  Collection<int, Assignment>  $on
     * @param  callable(int): ?User  $person  Reads a person, with a lock or without.
     * @return EloquentCollection<int, User>
     */
    private static function regularsJoining(Shift $shift, Collection $regulars, Collection $on, callable $person): EloquentCollection
    {
        $date = $shift->local_date->toDateString();
        $joining = new EloquentCollection;
        $takenOff = $regulars->isEmpty() ? collect() : ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.removed')->whereNotNull('affected_user_id')->pluck('affected_user_id');

        foreach ($regulars as $regular) {
            if (! $regular->activeOn($date) || $on->contains('user_id', $regular->user_id) || $joining->contains('id', $regular->user_id) || $takenOff->contains($regular->user_id)) {
                continue;
            }

            $user = $person($regular->user_id);

            if ($user?->isActive()) {
                $joining->push($user);
            }
        }

        return $joining;
    }

    /** @return array{emailed: int, removed: Collection<int, User>} */
    public function handle(User $actor, Shift $shift): array
    {
        Gate::forUser($actor)->authorize('restore', $shift);

        $result = DB::transaction(function () use ($actor, $shift) {
            $area = Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            // Then its weekly slot and the slot's regulars, before the shift: the order every scheduling action locks in, since restoreLocked() reads them.
            if ($shift->weekly_slot_id !== null) {
                WeeklySlot::query()->whereKey($shift->weekly_slot_id)->lockForUpdate()->first();
                SlotRegular::query()->where('weekly_slot_id', $shift->weekly_slot_id)->orderBy('id')->lockForUpdate()->get();
            }

            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            if ($locked->cancelled_at === null) {
                throw new DomainException('This shift isn\'t cancelled.');
            }

            if ($locked->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            $by = self::cancelledBy($locked);

            if ($by === self::CLOSURE) {
                throw new DomainException('Cancelled by the '.($locked->areaClosure?->reason ?? $locked->cancel_reason).' closure. Change the closure to bring it back.');
            }

            if ($by === self::SLOT) {
                throw new DomainException('The weekly slot doesn\'t run on this date any more. Change the slot to bring it back.');
            }

            $blocked = self::blockedBy($locked->load('weeklySlot'));

            if ($blocked !== null) {
                throw new DomainException($blocked);
            }

            $result = $this->restoreLocked($actor, $locked, []);
            $shift->setRawAttributes($locked->getAttributes(), true);

            // After the shift and assignment locks above. People whose shift came back at a new time get a change notice in the same transaction.
            $locked->setRelation('area', $area);
            $result['timeNotices'] = $result['timeChange'] === null ? [] : $this->notices->recordLocked($actor, [['shift' => $locked, 'back_on' => true] + $result['timeChange']]);

            return $result;
        });

        $shift->loadMissing('area');
        $timeNotices = $result['timeNotices'];
        $noticed = collect($timeNotices)->pluck('user.id');
        // One email per person: the time email, with the question, replaces "back on" for anyone whose shift came back at a new time.
        $emailed = $result['emailed'];
        defer(function () use ($emailed, $timeNotices, $noticed, $shift) {
            foreach ($timeNotices as $sent) {
                $sent['user']->notify($sent['notification']);
            }

            $emailed->reject(fn (User $person) => $noticed->contains($person->id))
                ->each(fn (User $person) => $person->notify(new ShiftRestoredNotification($shift)));
        });
        CoverFill::sendAfterCommit($result['covered']);

        return ['emailed' => $emailed->count(), 'removed' => $result['removed']];
    }
}
