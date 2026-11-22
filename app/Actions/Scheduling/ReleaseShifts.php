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
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Notifications\CoverRequestNotification;
use App\Notifications\ShadowLeftNotification;
use App\Notifications\ShadowSpotNotification;
use App\Notifications\ShiftsReleasedNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A volunteer says "Can't make it" (spec section 8.3): they release their place on one shift, or on every shift they're on in a range of dates. Each released regular place that opens a place becomes a cover request, emailed to everyone who could take the shift (decision 4). A shift that still has the people it needs after the person comes off it gets no cover request and nobody is asked; the person's email says so, and `$nobodyAsked` lists those shifts for the screen. Releasing one shift or a range never changes the person's weekly regular; giving up a weekly slot (decision 13) ends it through EndRegular::endOwn() and releases every future shift of it in the same transaction. When nobody but shadows is left on a shift, its shadows hear that their spot is on hold (decision 6). A shift that starts within CoverLadder::URGENT_HOURS and still has an open place is escalated in the same transaction, so leads and coordinators get the urgent email at once (instead of the cover request, for anyone who would get both) and the shadows get only "still nobody" (decision 10). A shadow who releases leaves the shadow spot with no cover request, and the coordinators are told (decision 14). Each person gets one email per action, listing every shift that concerns them. The refusals are written to be shown on screen. Volunteers reach this through ShiftPolicy::release(); the action itself checks the shift and the person's place on it again under the locks.
 */
class ReleaseShifts
{
    public const NOTE_LIMIT = 500;

    /** @var list<int> The shifts the last release took the person off without asking anybody, because they still had the people they need. */
    public array $nobodyAsked = [];

    public function __construct(private RemoveFromShift $remover, private CoverLadder $ladder, private EndRegular $endRegular) {}

    /** Releases the person's place on one shift. Returns the cover request, or null when they were a shadow (decision 14) or the shift still has the people it needs (`$nobodyAsked` then lists it). */
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
     * What slot() would release: the future, uncancelled shifts of the regular's weekly slot from `$from` on (inside the regular's own dates) that the person is on as a regular, in time order, with their areas. Changes nothing. The date is checked the same way slot() checks it, so the screen never confirms something that can't succeed.
     *
     * @return Collection<int, Shift>
     */
    public function slotPreview(User $person, SlotRegular $regular, string $from): Collection
    {
        if ($regular->user_id !== $person->id) {
            throw new DomainException('That isn\'t your weekly slot.');
        }

        $from = self::checkFrom($from)->toDateString();

        return Shift::query()
            ->where('weekly_slot_id', $regular->weekly_slot_id)
            ->where('local_date', '>=', $from)
            ->when($regular->ends_on !== null, fn (Builder $query) => $query->where('local_date', '<=', $regular->ends_on->toDateString()))
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id)->where('type', AssignmentType::Regular))
            ->with('area')->orderBy('starts_at')->orderBy('id')->get();
    }

    /**
     * Gives up the person's weekly slot from `$from` on (decision 13): the regular ends the day before, through EndRegular::endOwn(), and each future shift of the slot they're on becomes a cover request in the same transaction. A shift that has started stays theirs. Volunteers get the cover request in its "giving up their weekly slot" wording listing the dates, a shift inside CoverLadder::URGENT_HOURS escalates at once, and the person gets one email saying they're no longer the regular from that date, even when no shifts of it are on the calendar yet.
     *
     * @return Collection<int, Shift> The shifts released, in time order.
     */
    public function slot(User $person, SlotRegular $regular, string $from, ?string $note): Collection
    {
        $note = self::cleanNote($note);
        $from = self::checkFrom($from);
        $released = [];

        $this->endRegular->endOwn($person, $regular, $from->subDay()->toDateString(), function (Shift $lockedShift, Assignment $assignment) use ($person, $note, &$released) {
            $released[] = $this->releaseAndCollectLocked($person, $lockedShift->loadMissing('area'), $assignment, $note, 'slot');
        });

        $this->sendAfterCommit($person, $released, $note, ['weekday' => $regular->weeklySlot->weekdayName(), 'from' => $from->format('l, F j')]);

        return collect($released)->pluck('shift')->values();
    }

    /** The first date a slot is given up from, refused unless it's a real Y-m-d date from today on, with messages written to be shown on screen. */
    private static function checkFrom(string $from): CarbonImmutable
    {
        if (! RemoveFromShift::isDate($from)) {
            throw new DomainException('Pick a date.');
        }

        if ($from < Organization::current()->today()->toDateString()) {
            throw new DomainException('Pick a date from today on.');
        }

        return CarbonImmutable::parse($from, 'UTC');
    }

    /**
     * The shared step. Deletes the assignment through RemoveFromShift::removeLocked(), logged as assignment.removed about the person with `via` "release:{$via}". For a regular place that leaves the shift with an open place it creates the cover request, logged as cover_request.created with the shift as the subject, about the person (so RemovePerson rewrites the ids in it), and the request's values, note included, in `changes`. Returns the request, or null for a shadow or when the shift still has the people it needs, so no place opened. It sends nothing and doesn't escalate; releaseAndCollectLocked() does both. The people asked are attached to the request as its `recipients` relation (not stored), so the caller emails exactly the people counted in notified_count. Everyone who could take the shift is asked, leads and coordinators included, since this step doesn't escalate. The caller holds the area and shift locks and the assignment's row lock.
     */
    public function releaseLocked(User $person, Shift $lockedShift, Assignment $assignment, ?string $note, string $via): ?CoverRequest
    {
        return $this->releaseLockedLeavingOut($person, $lockedShift, $assignment, $note, $via, false);
    }

    /** releaseLocked(). When `$callerEscalates` is true (releaseAndCollectLocked(), which escalates a shift that escalatesNow()) and the shift escalates now, checked after the removal opens the place, the urgent email's audience is left out of the recipients, so each of those people hears about the shift once. */
    private function releaseLockedLeavingOut(User $person, Shift $lockedShift, Assignment $assignment, ?string $note, string $via, bool $callerEscalates): ?CoverRequest
    {
        $note = self::cleanNote($note);

        $this->remover->removeLocked($person, $lockedShift, $assignment, ['via' => 'release:'.$via]);

        // A shadow doesn't hold a place, and a shift that had more people than it needs still has them all, so neither opens a place to cover.
        if ($assignment->type === AssignmentType::Shadow || $lockedShift->openPlaces() === 0) {
            return null;
        }

        // The request isn't saved yet, so recipients() can't leave the releaser out by their request; they're left out here.
        $leftOut = [$person->id];

        if ($callerEscalates && self::escalatesNow($lockedShift)) {
            $leftOut = array_merge($leftOut, array_keys(CoverLadder::urgentAudience($lockedShift->loadMissing('area')->area)));
        }

        $recipients = CoverEligibility::recipients($lockedShift)->reject(fn (User $recipient) => in_array($recipient->id, $leftOut, true))->values();

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

    /** Whether releasing a place on this shift escalates it at once: it starts within URGENT_HOURS and still has an open place, the same test CoverLadder::step() makes. */
    private static function escalatesNow(Shift $lockedShift): bool
    {
        return $lockedShift->cancelled_at === null
            && $lockedShift->starts_at->greaterThan(now())
            && $lockedShift->starts_at->lessThanOrEqualTo(now()->addHours(CoverLadder::URGENT_HOURS))
            && $lockedShift->openPlaces() > 0;
    }

    /**
     * Releases one place and works out who hears about it, without sending: the cover request's recipients, the shadows told their spot is on hold, and, when the shift escalates at once, the escalation's emails (which tell the shadows instead, when nobody but shadows is left). Every caller that releases places, giving up a weekly slot included, calls this inside its transaction for each place and passes the results to sendAfterCommit() once, so each person gets one email per action.
     *
     * `nobodyAsked` is true for a regular place on a shift that still has the people it needs, where nobody is asked and nothing escalates.
     *
     * @return array{shift: Shift, request: ?CoverRequest, nobodyAsked: bool, recipients: Collection<int, User>, sends: list<array{0: User, 1: Notification}>, shadowsTold: list<string>}
     */
    protected function releaseAndCollectLocked(User $person, Shift $lockedShift, Assignment $assignment, ?string $note, string $via): array
    {
        $request = $this->releaseLockedLeavingOut($person, $lockedShift, $assignment, $note, $via, true);
        $released = ['shift' => $lockedShift, 'request' => $request, 'nobodyAsked' => $request === null && $assignment->type === AssignmentType::Regular, 'recipients' => collect(), 'sends' => [], 'shadowsTold' => []];

        if ($request === null) {
            return $released;
        }

        $released['recipients'] = $request->getRelation('recipients');

        // A shadow's spot is on hold only when nobody but shadows is left on the shift (decision 6).
        $shadows = $lockedShift->filledCount() > 0 ? collect() : Assignment::query()->with('user')
            ->where('shift_id', $lockedShift->id)->where('type', AssignmentType::Shadow)->orderBy('id')->get()->pluck('user');

        if (self::escalatesNow($lockedShift)) {
            // Every unescalated open request, the way the ladder would take them, so it never escalates this shift a second time.
            $unescalated = CoverRequest::query()->open()->whereNull('escalated_at')->where('shift_id', $lockedShift->id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();
            $released['sends'] = $this->ladder->escalateLocked($lockedShift, $unescalated, $person);
        } else {
            $released['sends'] = $shadows->map(fn (User $shadow) => [$shadow, new ShadowSpotNotification($lockedShift, ShadowSpotNotice::OnHold, $person->firstName())])->all();
        }

        $released['shadowsTold'] = $shadows->filter(fn (User $shadow) => $shadow->canSignIn())->map(fn (User $shadow) => $shadow->firstName())->values()->all();

        return $released;
    }

    /**
     * Sends, after the response, one email per person for everything this action released: the releaser's "You're off" (when they left at least one regular place), one cover request per recipient listing the shifts they could take, the "on hold" and escalation emails merged so each person gets one of each kind listing every shift, and one "can't make their shadow shift" per coordinator, with the note.
     *
     * When the person gave up their weekly slot, `$gaveUpSlot` holds the slot's weekday and the first date they're no longer the regular ("Thursday, October 29"): the cover requests use the "giving up their weekly slot" wording, and the person's email says they're no longer the regular, sent even when nothing was released.
     *
     * @param  list<array{shift: Shift, request: ?CoverRequest, nobodyAsked: bool, recipients: Collection<int, User>, sends: list<array{0: User, 1: Notification}>, shadowsTold: list<string>}>  $released
     * @param  array{weekday: string, from: string}|null  $gaveUpSlot
     */
    protected function sendAfterCommit(User $person, array $released, ?string $note, ?array $gaveUpSlot = null): void
    {
        $releaser = $person->firstName();
        $regularPlaces = array_values(array_filter($released, fn (array $place) => $place['request'] !== null || $place['nobodyAsked']));
        $shadowShifts = collect($released)->filter(fn (array $place) => $place['request'] === null && ! $place['nobodyAsked'])->pluck('shift')->values();
        $nobodyAsked = collect($regularPlaces)->filter(fn (array $place) => $place['nobodyAsked'])->map(fn (array $place) => $place['shift']->id)->values()->all();
        $this->nobodyAsked = $nobodyAsked;
        $sends = [];
        $forRecipient = [];

        foreach ($regularPlaces as $place) {
            foreach ($place['recipients'] as $recipient) {
                $forRecipient[$recipient->id] ??= [$recipient, collect()];
                $forRecipient[$recipient->id][1]->push($place['shift']);
            }
        }

        if ($regularPlaces !== [] || $gaveUpSlot !== null) {
            $regularShifts = collect($regularPlaces)->pluck('shift');
            $sends[] = [$person, new ShiftsReleasedNotification(
                collect($released)->pluck('shift'),
                collect($regularPlaces)->mapWithKeys(fn (array $place) => [$place['shift']->id => $place['shadowsTold']])->all(),
                $this->stillRegularOn($person, $regularShifts),
                $shadowShifts->pluck('id')->all(),
                $gaveUpSlot,
                $nobodyAsked,
            )];
        }

        foreach ($forRecipient as [$recipient, $shifts]) {
            $sends[] = [$recipient, new CoverRequestNotification($shifts, $releaser, $note, gaveUpSlot: $gaveUpSlot !== null)];
        }

        foreach ($released as $place) {
            array_push($sends, ...$place['sends']);
        }

        if ($shadowShifts->isNotEmpty()) {
            $coordinators = User::query()->people()->whereNull('deactivated_at')->whereKeyNot($person->id)
                ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::Coordinator->value))
                ->orderBy('id')->get();

            foreach ($coordinators as $coordinator) {
                $sends[] = [$coordinator, new ShadowLeftNotification($releaser, $shadowShifts, $note)];
            }
        }

        $sends = self::merged($sends);

        defer(function () use ($sends) {
            foreach ($sends as [$recipient, $notification]) {
                $recipient->notify($notification);
            }
        });
    }

    /**
     * Folds emails of the same kind to the same person into one, using the notification's merge(): the urgent emails of several escalated shifts, or a shadow's "on hold" or "still nobody" for several dates. Kept in the order first sent.
     *
     * @param  list<array{0: User, 1: Notification}>  $sends
     * @return list<array{0: User, 1: Notification}>
     */
    private static function merged(array $sends): array
    {
        $merged = [];

        foreach ($sends as [$recipient, $notification]) {
            $key = $recipient->id.' '.$notification::class.($notification instanceof ShadowSpotNotification ? ' '.$notification->notice->value : '');

            if (! isset($merged[$key])) {
                $merged[$key] = [$recipient, $notification];
            } elseif (method_exists($merged[$key][1], 'merge')) {
                $merged[$key][1]->merge($notification);
            } else {
                // A kind that can't be merged is sent as it is rather than dropped.
                $merged[$key.' '.count($merged)] = [$recipient, $notification];
            }
        }

        return array_values($merged);
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
