<?php

namespace App\Actions\Scheduling;

use App\Cover\CoverEligibility;
use App\Cover\CoverLadder;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\ShadowSpotNotice;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\CoverRequest;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Notifications\CoverRequestNotification;
use App\Notifications\ShadowLeftNotification;
use App\Notifications\ShadowSpotNotification;
use App\Notifications\ShiftsReleasedNotification;
use Closure;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A volunteer says "Can't make it" (spec section 8.3): they release their place on one shift, or on every shift they're on in a range of dates. Each released regular place becomes a cover request, emailed to everyone who could take the shift (decision 4). Releasing never changes the person's weekly regular. When nobody but shadows is left on a shift, its shadows hear that their spot is on hold (decision 6). A shift that starts within CoverLadder::URGENT_HOURS is escalated in the same transaction, so leads and coordinators get the urgent email at once and the shadows get only "still nobody" (decision 10). A shadow who releases leaves the shadow spot with no cover request, and the coordinators are told (decision 14). Each person gets one email per action, listing every shift that concerns them. The refusals are written to be shown on screen. Volunteers reach this through ShiftPolicy::release(); the action itself checks the shift and the person's place on it again under the locks.
 */
class ReleaseShifts
{
    public const NOTE_LIMIT = 500;

    public function __construct(private RemoveFromShift $remover, private CoverLadder $ladder) {}

    /** Releases the person's place on one shift. Returns the cover request, or null when they were a shadow (decision 14). */
    public function one(User $person, Shift $shift, ?string $note): ?CoverRequest
    {
        $note = self::cleanNote($note);

        $released = DB::transaction(function () use ($person, $shift, $note) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $locked = Shift::query()->with('area')->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $assignment = Assignment::query()->with('user')->where('shift_id', $locked->id)->where('user_id', $person->id)->lockForUpdate()->first();

            if ($assignment === null) {
                throw new DomainException('You\'re not on this shift any more.');
            }

            if ($locked->cancelled_at !== null) {
                throw new DomainException('This shift was cancelled.');
            }

            if ($locked->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            return [$this->releaseAndCollectLocked($person, $locked, $assignment, $note, 'one')];
        });

        $this->sendAfterCommit($person, $released, $note);

        return $released[0]['request'];
    }

    /**
     * What range() would release: the person's future, uncancelled shifts in those dates, in time order. Changes nothing. The dates are checked the same way, with the same messages, as RemoveFromShift::rangePreview(), and `lastOnCalendar` is the generation window's last date when the range goes past it.
     *
     * @return array{shifts: Collection<int, Shift>, lastOnCalendar: ?string}
     */
    public function rangePreview(User $person, string $from, string $to): array
    {
        RemoveFromShift::checkDates($from, $to);

        return [
            'shifts' => $this->inRange($person, $from, $to)->with('area')->orderBy('starts_at')->orderBy('id')->get(),
            'lastOnCalendar' => RemoveFromShift::lastOnCalendar($to),
        ];
    }

    /** @return Collection<int, Shift> The shifts released, in time order. */
    public function range(User $person, string $from, string $to, ?string $note): Collection
    {
        $note = self::cleanNote($note);
        RemoveFromShift::checkDates($from, $to);

        // Found before the transaction, so no read inside it fixes the snapshot ahead of the locks.
        $areaIds = $this->inRange($person, $from, $to)->pluck('area_id')->unique()->sort()->values();

        $released = DB::transaction(function () use ($person, $from, $to, $note, $areaIds) {
            Area::query()->whereKey($areaIds)->orderBy('id')->lockForUpdate()->get();

            $shifts = $this->inRange($person, $from, $to)->whereIn('area_id', $areaIds)->orderBy('starts_at')->orderBy('id')->lockForUpdate()->get();
            $shifts->load('area');

            // Read under lock, so a place given up or removed since the preview isn't released twice.
            $assignments = Assignment::query()->with('user')->where('user_id', $person->id)->whereIn('shift_id', $shifts->modelKeys())->lockForUpdate()->get()->keyBy('shift_id');
            $shifts = $shifts->filter(fn (Shift $shift) => $assignments->has($shift->id))->values();

            if ($shifts->isEmpty()) {
                throw new DomainException('You\'re not on any shifts on those dates.');
            }

            return $shifts->map(fn (Shift $shift) => $this->releaseAndCollectLocked($person, $shift, $assignments[$shift->id], $note, 'range'))->all();
        });

        $this->sendAfterCommit($person, $released, $note);

        return collect($released)->pluck('shift')->values();
    }

    /**
     * The shared step, also used for giving up a weekly slot. Deletes the assignment through RemoveFromShift::removeLocked(), logged as assignment.removed about the person with `via` "release:{$via}". For a regular place it creates the cover request, logged as cover_request.created with the shift as the subject, about the person (so RemovePerson rewrites the ids in it), and the request's values, note included, in `changes`. Returns the request, or null for a shadow. It sends nothing. The people asked are attached to the request as its `recipients` relation (not stored), so the caller emails exactly the people counted in notified_count. The caller holds the area and shift locks and the assignment's row lock.
     */
    public function releaseLocked(User $person, Shift $lockedShift, Assignment $assignment, ?string $note, string $via): ?CoverRequest
    {
        $note = self::cleanNote($note);

        $this->remover->removeLocked($person, $lockedShift, $assignment, ['via' => 'release:'.$via]);

        if ($assignment->type === AssignmentType::Shadow) {
            return null;
        }

        // The request isn't saved yet, so recipients() can't leave the releaser out by their request; they're left out here.
        $recipients = CoverEligibility::recipients($lockedShift)->reject(fn (User $recipient) => $recipient->id === $person->id)->values();

        $request = new CoverRequest(['shift_id' => $lockedShift->id, 'released_by' => $person->id, 'note' => $note, 'notified_count' => $recipients->count()]);
        $changes = ChangeLogEntry::changesOf($request);
        $request->save();

        ChangeLogEntry::record($person, 'cover_request.created', $lockedShift, ['cover_request_id' => $request->id], $changes, about: $person);

        return $request->setRelation('recipients', $recipients);
    }

    /** The note as stored: trimmed, null when empty, and refused over NOTE_LIMIT characters. */
    public static function cleanNote(?string $note): ?string
    {
        $note = $note === null ? '' : trim($note);

        if (mb_strlen($note) > self::NOTE_LIMIT) {
            throw new DomainException('Keep the note under 500 characters.');
        }

        return $note === '' ? null : $note;
    }

    /**
     * Releases one place and works out who hears about it, without sending: the cover request's recipients, the shadows told their spot is on hold, and, inside URGENT_HOURS, the escalation's send closure (which tells the shadows instead, when nobody but shadows is left).
     *
     * @return array{shift: Shift, request: ?CoverRequest, recipients: Collection<int, User>, onHold: Collection<int, User>, shadowsTold: list<string>, escalation: ?Closure}
     */
    private function releaseAndCollectLocked(User $person, Shift $lockedShift, Assignment $assignment, ?string $note, string $via): array
    {
        $request = $this->releaseLocked($person, $lockedShift, $assignment, $note, $via);
        $released = ['shift' => $lockedShift, 'request' => $request, 'recipients' => collect(), 'onHold' => collect(), 'shadowsTold' => [], 'escalation' => null];

        if ($request === null) {
            return $released;
        }

        $released['recipients'] = $request->getRelation('recipients');

        // A shadow's spot is on hold only when nobody but shadows is left on the shift (decision 6).
        $shadows = $lockedShift->filledCount() > 0 ? collect() : Assignment::query()->with('user')
            ->where('shift_id', $lockedShift->id)->where('type', AssignmentType::Shadow)->orderBy('id')->get()->pluck('user');

        if ($lockedShift->starts_at->lessThanOrEqualTo(now()->addHours(CoverLadder::URGENT_HOURS))) {
            // Every unescalated open request, the way the ladder would take them, so it never escalates this shift a second time.
            $unescalated = CoverRequest::query()->open()->whereNull('escalated_at')->where('shift_id', $lockedShift->id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $released['escalation'] = $this->ladder->escalateLocked($lockedShift, $unescalated, $person);
        } else {
            $released['onHold'] = $shadows;
        }

        $released['shadowsTold'] = $shadows->filter(fn (User $shadow) => $shadow->canSignIn())->map(fn (User $shadow) => $shadow->firstName())->values()->all();

        return $released;
    }

    /**
     * Sends, after the response, one email per person for everything this action released: the releaser's "You're off", one cover request per recipient listing the shifts they could take, one "on hold" per shadow, the escalation emails, and one "can't make their shadow shift" per coordinator.
     *
     * @param  list<array{shift: Shift, request: ?CoverRequest, recipients: Collection<int, User>, onHold: Collection<int, User>, shadowsTold: list<string>, escalation: ?Closure}>  $released
     */
    private function sendAfterCommit(User $person, array $released, ?string $note): void
    {
        $releaser = $person->firstName();
        $regularPlaces = array_values(array_filter($released, fn (array $place) => $place['request'] !== null));
        $shadowShifts = collect($released)->filter(fn (array $place) => $place['request'] === null)->pluck('shift')->values();
        $sends = [];

        $forRecipient = [];
        $forShadow = [];

        foreach ($regularPlaces as $place) {
            foreach ($place['recipients'] as $recipient) {
                $forRecipient[$recipient->id] ??= [$recipient, collect()];
                $forRecipient[$recipient->id][1]->push($place['shift']);
            }

            foreach ($place['onHold'] as $shadow) {
                $forShadow[$shadow->id] ??= [$shadow, collect()];
                $forShadow[$shadow->id][1]->push($place['shift']);
            }
        }

        if ($regularPlaces !== []) {
            $sends[] = [$person, new ShiftsReleasedNotification(
                collect($regularPlaces)->pluck('shift'),
                collect($regularPlaces)->mapWithKeys(fn (array $place) => [$place['shift']->id => $place['shadowsTold']])->all(),
                $this->stillRegularOn($person, collect($regularPlaces)->pluck('shift')),
            )];
        }

        foreach ($forRecipient as [$recipient, $shifts]) {
            $sends[] = [$recipient, new CoverRequestNotification($shifts, $releaser, $note)];
        }

        foreach ($forShadow as [$shadow, $shifts]) {
            $sends[] = [$shadow, new ShadowSpotNotification($shifts->first(), ShadowSpotNotice::OnHold, $releaser, alsoOnHold: $shifts->slice(1)->values())];
        }

        if ($shadowShifts->isNotEmpty()) {
            $coordinators = User::query()->people()->whereNull('deactivated_at')->whereKeyNot($person->id)
                ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::Coordinator->value))
                ->orderBy('id')->get();

            foreach ($coordinators as $coordinator) {
                $sends[] = [$coordinator, new ShadowLeftNotification($releaser, $shadowShifts)];
            }
        }

        $escalations = array_values(array_filter(array_column($released, 'escalation')));

        defer(function () use ($sends, $escalations) {
            foreach ($sends as [$recipient, $notification]) {
                $recipient->notify($notification);
            }

            foreach ($escalations as $send) {
                $send();
            }
        });
    }

    /**
     * The ids of the shifts on a weekly slot the person is still the regular for on that date, so their email can say they stay the regular.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return list<int>
     */
    private function stillRegularOn(User $person, Collection $shifts): array
    {
        $slotIds = $shifts->pluck('weekly_slot_id')->filter()->unique()->values();

        if ($slotIds->isEmpty()) {
            return [];
        }

        $regulars = SlotRegular::query()->with('weeklySlot')->where('user_id', $person->id)->whereIn('weekly_slot_id', $slotIds)->get();

        return $shifts->filter(fn (Shift $shift) => $regulars->contains(fn (SlotRegular $regular) => $regular->weekly_slot_id === $shift->weekly_slot_id
            && $regular->activeOn($shift->local_date->toDateString())))->pluck('id')->values()->all();
    }

    private function inRange(User $person, string $from, string $to): Builder
    {
        return Shift::query()
            ->whereBetween('local_date', [$from, $to])
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id));
    }
}
