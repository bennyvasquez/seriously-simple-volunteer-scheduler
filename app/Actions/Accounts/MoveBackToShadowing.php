<?php

namespace App\Actions\Accounts;

use App\Actions\Scheduling\ReleaseFutureSchedule;
use App\Actions\Scheduling\ReleaseShifts;
use App\Enums\AssignmentType;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\RoleAssignment;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Notifications\MovedBackNotification;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A coordinator moves a cleared volunteer back to shadowing (spec section 9, screen 10), keeping or releasing their upcoming regular shifts. The same people who clear someone can move them back (UserPolicy::clear()). Someone who holds a role stays cleared (decision 4), so they're refused until their role is removed.
 *
 * Moving back commits first, in its own transaction, the way turning someone off does: it locks the person's row, sets `cleared_at` to null and `shadowing_since` to now (so their shadow progress starts over, see ShadowProgress) and logs `user.moved_back` about them, with both columns' old and new values and the coordinator's note (if any) in `changes` and the choice in `details.release`. From then on CoverEligibility leaves them out of cover requests and refuses their takes. Releasing runs after that commit, through ReleaseFutureSchedule::releaseRegularPlaces(). If the release fails after the move back committed, the person stays not cleared with their shifts and regulars kept, and the coordinator can release them through the calendar.
 *
 * The person gets one email after the response, "You've been moved back to shadowing", listing what was released, and no "You're off" email. The note stays in the change log and isn't emailed.
 */
class MoveBackToShadowing
{
    public function __construct(private readonly ReleaseFutureSchedule $releaseFutureSchedule) {}

    /**
     * What the screen asks about: the person's future, uncancelled shifts where they hold a regular place, in time order with their areas, and their current or future regulars, with their slots and areas. Changes nothing.
     *
     * @return array{shifts: Collection<int, Shift>, regulars: Collection<int, SlotRegular>}
     */
    public function preview(User $person): array
    {
        return [
            'shifts' => Shift::query()
                ->whereNull('cancelled_at')
                ->where('starts_at', '>', now())
                ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id)->where('type', AssignmentType::Regular))
                ->with('area')->orderBy('starts_at')->orderBy('id')->get(),
            'regulars' => $this->regulars($person),
        ];
    }

    /**
     * The person's current or future regulars, the ones releasing ends, with their slots and areas.
     *
     * @return Collection<int, SlotRegular>
     */
    private function regulars(User $person): Collection
    {
        return ReleaseFutureSchedule::regularsQuery($person, Organization::current()->today()->toDateString())
            ->with('weeklySlot.area')->orderBy('id')->get();
    }

    /** Why this person can't be moved back, written to be shown on screen, or null when they can. handle() asks again under the person's row lock. */
    public function refusal(User $person): ?string
    {
        if (RoleAssignment::query()->where('user_id', $person->id)->exists()) {
            return $person->firstName().' has a role, so they stay cleared. Remove their role first.';
        }

        if (! $person->isCleared()) {
            return $person->firstName().' isn\'t cleared.';
        }

        return null;
    }

    /**
     * Moves them back, and with `$release` releases their upcoming regular places and ends their regulars. The note is trimmed and kept up to 500 characters (ReleaseShifts::cleanNote()). `$person` reads as not cleared, with its new `shadowing_since`, afterwards.
     *
     * @return Collection<int, Shift> The shifts released, in time order, with their areas.
     */
    public function handle(User $actor, User $person, bool $release, ?string $note): Collection
    {
        Gate::forUser($actor)->authorize('clear', $person);

        $note = ReleaseShifts::cleanNote($note);

        // GrantRole holds this row lock while it adds a role, so a role granted meanwhile is seen here.
        $locked = DB::transaction(function () use ($actor, $person, $release, $note) {
            $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();

            if (($refusal = $this->refusal($locked)) !== null) {
                throw new DomainException($refusal);
            }

            $locked->forceFill(['cleared_at' => null, 'shadowing_since' => now()]);
            $changes = ChangeLogEntry::changesOf($locked);
            $locked->save();

            if ($note !== null) {
                $changes['note'] = ['old' => null, 'new' => $note];
            }

            ChangeLogEntry::record($actor, 'user.moved_back', $locked, ['release' => $release], $changes);

            return $locked;
        });

        $person->forceFill(['cleared_at' => null, 'shadowing_since' => $locked->shadowing_since])->syncOriginalAttributes(['cleared_at', 'shadowing_since']);

        $shifts = collect();
        $noLongerRegularOn = [];

        if ($release) {
            $regulars = $this->regulars($locked);
            $shiftIds = $this->releaseFutureSchedule->releaseRegularPlaces($actor, $locked)->keys();
            $shifts = Shift::query()->with('area')->whereKey($shiftIds)->orderBy('starts_at')->orderBy('id')->get();
            $noLongerRegularOn = self::slotWords($regulars);
        }

        if ($locked->canSignIn()) {
            $organization = Organization::current();
            $email = new MovedBackNotification($actor, $organization, (int) $organization->required_shadow_shifts, $shifts, $noLongerRegularOn);
            defer(fn () => $locked->notify($email));
        }

        return $shifts;
    }

    /**
     * Each regular's slot as "Thursdays, Front desk", once per slot, for the screen and the email.
     *
     * @param  Collection<int, SlotRegular>  $regulars  With their slots and areas loaded.
     * @return list<string>
     */
    public static function slotWords(Collection $regulars): array
    {
        return $regulars->map(fn (SlotRegular $regular) => $regular->weeklySlot->weekdayName().'s, '.$regular->weeklySlot->area->name)->unique()->values()->all();
    }
}
