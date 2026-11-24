<?php

namespace App\Actions\Scheduling;

use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\RemovedFromShiftsNotification;
use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A coordinator or area lead removes someone from one shift, or from every shift they're on in a range of dates (in the areas the actor manages). Nothing is removed from a shift that has started. A cancelled future shift is allowed, but nobody is emailed about it. A range sends the person one email listing every shift. The refusals are written to be shown on screen. removeLocked() is the shared step: other actions (ending a regular, turning someone off or removing them) call it with the shift already locked and send any email themselves.
 */
class RemoveFromShift
{
    /** Deletes the assignment, ends the person's open change notices on the shift and logs the removal, with the shift as the subject and about the person. The caller holds the area and shift locks, has authorized the actor and sends any email. `$via` is merged into the entry's details, so a caller can say how the removal came about. */
    public function removeLocked(?User $actor, Shift $lockedShift, Assignment $assignment, array $via): void
    {
        $person = $assignment->user;

        // A copy with the person set to nobody, so the entry holds the old and new values the same way every other entry does.
        $after = (new Assignment)->setRawAttributes($assignment->getAttributes(), true);
        $after->user_id = null;
        $changes = ChangeLogEntry::changesOf($after);

        $assignment->delete();

        // Their change notices on this shift are over; the leaving is what's logged.
        ChangeNotice::query()->open()->where('user_id', $assignment->user_id)->where('shift_id', $lockedShift->id)->update(['ended_at' => now()]);

        ChangeLogEntry::record($actor, 'assignment.removed', $lockedShift, ['assignment_id' => $assignment->id, 'type' => $assignment->type->value] + $via, $changes, about: $person);
    }

    public function one(User $actor, Shift $shift, Assignment $assignment): void
    {
        Gate::forUser($actor)->authorize('removePerson', $shift);

        $person = $assignment->user;

        $locked = DB::transaction(function () use ($actor, $shift, $assignment) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();

            $current = Assignment::query()->with('user')->where('shift_id', $locked->id)->whereKey($assignment->id)->first();

            if ($current === null) {
                throw new DomainException($assignment->user->shortName().' isn\'t on this shift any more.');
            }

            if ($locked->starts_at->lessThanOrEqualTo(now())) {
                throw new DomainException('This shift has already started.');
            }

            $this->removeLocked($actor, $locked, $current, []);

            return $locked;
        });

        $shift->setRawAttributes($locked->getAttributes(), true);

        if ($locked->cancelled_at === null) {
            $shifts = collect([$locked->load('area')]);
            defer(fn () => $person->notify(new RemovedFromShiftsNotification($shifts)));
        }
    }

    /** @return Collection<int, Shift> The shifts the person was removed from. */
    public function range(User $actor, User $person, string $from, string $to): Collection
    {
        self::checkDates($from, $to);

        // Found before the transaction, so no read inside it fixes the snapshot ahead of the locks.
        $areaIds = $this->candidates($actor, $person, $from, $to)['shifts']->pluck('area_id')->unique()->sort()->values();

        $shifts = DB::transaction(function () use ($actor, $person, $from, $to, $areaIds) {
            Area::query()->whereKey($areaIds)->orderBy('id')->lockForUpdate()->get();

            $shifts = $this->inRange($person, $from, $to)->whereIn('area_id', $areaIds)->orderBy('starts_at')->orderBy('id')->lockForUpdate()->get();
            $shifts->load('area');
            $shifts = $shifts->filter(fn (Shift $shift) => Gate::forUser($actor)->allows('removePerson', $shift))->values();

            // Read under lock, so someone removed since the preview isn't counted, logged or emailed.
            $assignments = Assignment::query()->with('user')->where('user_id', $person->id)->whereIn('shift_id', $shifts->modelKeys())->lockForUpdate()->get()->keyBy('shift_id');
            $shifts = $shifts->filter(fn (Shift $shift) => $assignments->has($shift->id))->values();

            if ($shifts->isEmpty()) {
                throw new DomainException($person->shortName().' isn\'t on any shifts you manage on those dates.');
            }

            foreach ($shifts as $shift) {
                $this->removeLocked($actor, $shift, $assignments[$shift->id], ['via' => 'range', 'from' => $from, 'to' => $to]);
            }

            return $shifts;
        });

        $emailed = $shifts->filter(fn (Shift $shift) => $shift->cancelled_at === null)->values();

        if ($emailed->isNotEmpty()) {
            defer(fn () => $person->notify(new RemovedFromShiftsNotification($emailed)));
        }

        return $shifts;
    }

    /**
     * What range() would remove the person from, and how many of their shifts in those dates are in areas the actor can't manage. Changes nothing. When the range goes past the generation window (today plus the organization's weeks ahead), `lastOnCalendar` is the window's last date (Y-m-d), so the screen can say the later shifts aren't included; otherwise it's null.
     *
     * @return array{shifts: Collection<int, Shift>, otherAreas: int, lastOnCalendar: ?string}
     */
    public function rangePreview(User $actor, User $person, string $from, string $to): array
    {
        self::checkDates($from, $to);

        return $this->candidates($actor, $person, $from, $to) + ['lastOnCalendar' => self::lastOnCalendar($to)];
    }

    /** The generation window's last date (today plus the organization's weeks ahead, Y-m-d) when `$to` goes past it, or null. Shared with ReleaseShifts::rangePreview(). */
    public static function lastOnCalendar(string $to): ?string
    {
        $organization = Organization::current();
        $last = CarbonImmutable::parse($organization->today()->toDateString(), 'UTC')->addDays($organization->weeks_ahead * 7 - 1)->toDateString();

        return $to > $last ? $last : null;
    }

    /** @return array{shifts: Collection<int, Shift>, otherAreas: int} */
    private function candidates(User $actor, User $person, string $from, string $to): array
    {
        $all = $this->inRange($person, $from, $to)->with('area')->orderBy('starts_at')->orderBy('id')->get();
        [$managed, $others] = $all->partition(fn (Shift $shift) => Gate::forUser($actor)->allows('removePerson', $shift));

        return ['shifts' => $managed->values(), 'otherAreas' => $others->count()];
    }

    private function inRange(User $person, string $from, string $to)
    {
        return Shift::query()
            ->whereBetween('local_date', [$from, $to])
            ->where('starts_at', '>', now())
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id));
    }

    /** Whether the text is a real date written as Y-m-d, the only form the screens send. Shared with checkDates(), EndRegular::endOwn() and ReleaseShifts::slot(). */
    public static function isDate(string $date): bool
    {
        // Carbon throws on text that isn't a date at all, rather than returning false.
        try {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date);
        } catch (InvalidFormatException) {
            return false;
        }

        return $parsed !== false && $parsed->format('Y-m-d') === $date;
    }

    /** Refuses a range that isn't two real dates, starts before today or ends before it starts, with messages written to be shown on screen. Shared with ReleaseShifts. */
    public static function checkDates(string $from, string $to): void
    {
        if (! self::isDate($from) || ! self::isDate($to)) {
            throw new DomainException('Pick two dates.');
        }

        if ($from < Organization::current()->today()->toDateString()) {
            throw new DomainException('Pick dates from today on.');
        }

        if ($to < $from) {
            throw new DomainException('The last date can\'t be before the first date.');
        }
    }
}
