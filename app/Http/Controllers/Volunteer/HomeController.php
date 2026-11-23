<?php

namespace App\Http\Controllers\Volunteer;

use App\Cover\CoverEligibility;
use App\Cover\ShadowProgress;
use App\Enums\AssignmentType;
use App\Enums\SlotFrequency;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/**
 * A volunteer's Home (decision 16): the next 14 days of their shifts and the open shifts they could take, plus "All my shifts". Someone who isn't cleared yet sees their shadow progress, their next shadow shift and the open shadow spots instead (spec section 9, wireframe 6). The person's own cancelled shifts stay on both lists, tagged "Cancelled" with the reason and no "Can't make it", since cancelled shifts stay visible. Each list runs a fixed number of queries however many shifts there are, so nothing here asks the database once per card.
 */
class HomeController extends Controller
{
    public function home(Request $request): View
    {
        $user = $request->user();
        // Read without Organization::current(), so Home still opens on an install whose organization isn't set up yet; the column defaults apply then.
        $organization = Organization::query()->first(['timezone', 'required_shadow_shifts']);
        $timezone = $organization?->timezone ?? 'UTC';
        $next = $this->nextShifts($user);

        if (! $user->isCleared()) {
            $nextShadow = $next->first(fn (Shift $shift) => $shift->card_tag === 'Shadowing');
            $nextShadow?->setAttribute('shadowing_with', ReleaseController::names(
                $nextShadow->assignments()->with('user')->where('type', AssignmentType::Regular)->orderBy('id')->get()->map(fn ($assignment) => $assignment->user->firstName())->all()
            ));

            return view('volunteer.home', [
                'user' => $user,
                'timezone' => $timezone,
                'next' => $next->reject(fn (Shift $shift) => $shift->is($nextShadow))->values(),
                'nextShadow' => $nextShadow,
                'done' => ShadowProgress::done($user),
                'required' => $organization?->required_shadow_shifts ?? 2,
                'spots' => $this->openShadowSpots($user),
            ]);
        }

        return view('volunteer.home', [
            'user' => $user,
            'timezone' => $timezone,
            'next' => $next,
            'open' => $this->openShifts($user),
        ]);
    }

    /**
     * The shadow spots this person could sign up for in the next `$days` days, by start time: shifts that haven't started, aren't cancelled, have a free shadow spot and at least one regular on them, and that the person isn't on and isn't on an overlapping shift. Each carries `shadowing_with`, the regulars' first names ("Sam and Jordan"). The same rules as TakeShadowSpot::refusal(), worked out in memory so the list runs a fixed number of queries.
     *
     * @return Collection<int, Shift>
     */
    public function openShadowSpots(User $user, int $days = 14): Collection
    {
        $now = now()->toImmutable();
        $mine = $this->shiftsFrom($user, $now->addDays($days), false);

        return Shift::query()->active()
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays($days))
            ->where('shadow_spots', '>', 0)
            ->with(['area', 'assignments' => fn ($assignments) => $assignments->orderBy('id'), 'assignments.user'])
            ->orderBy('starts_at')->orderBy('id')
            ->get()
            ->filter(fn (Shift $shift) => ! $shift->assignments->contains('user_id', $user->id)
                && $shift->filledCount() > 0
                && $shift->assignments->where('type', AssignmentType::Shadow)->count() < $shift->shadow_spots
                && CoverEligibility::overlapRefusal($user, $shift, $mine) === null)
            ->each(fn (Shift $shift) => $shift->setAttribute('shadowing_with', ReleaseController::names(
                $shift->assignments->where('type', AssignmentType::Regular)->map(fn ($assignment) => $assignment->user->firstName())->values()->all()
            )))
            ->values();
    }

    public function myShifts(Request $request): View
    {
        $user = $request->user();
        $timezone = Organization::query()->value('timezone') ?? 'UTC';
        $months = $this->allFuture($user)
            ->groupBy(fn (Shift $shift) => $shift->starts_at->setTimezone($timezone)->format('F Y'));

        return view('volunteer.my-shifts', ['months' => $months, 'timezone' => $timezone]);
    }

    /**
     * The shifts this person could take in the next `$days` days, cover requests first (each carries `cover_names`, the first names of the people who can't make it, "Someone" for a removed person), then the rest by start time. A shift qualifies when it hasn't started, isn't cancelled, has an open place and CoverEligibility::refusal() has nothing against it. `has_no_regular` is true for a weekly slot date with no regular from then on, the dates TakeShift::choices() offers "every week" for.
     *
     * @return Collection<int, Shift>
     */
    public function openShifts(User $user, int $days = 14): Collection
    {
        $now = now()->toImmutable();
        $mine = $this->shiftsFrom($user, $now->addDays($days), false);

        return Shift::query()->active()
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays($days))
            ->with([
                'area',
                'assignments',
                'weeklySlot.regulars',
                'coverRequests' => fn ($requests) => $requests->open()->orderBy('created_at')->orderBy('id'),
                'coverRequests.releasedBy',
            ])
            ->orderBy('starts_at')->orderBy('id')
            ->get()
            ->filter(fn (Shift $shift) => $shift->openPlaces() > 0 && CoverEligibility::refusal($user, $shift, $mine) === null)
            ->each(function (Shift $shift) {
                // Only the oldest openPlaces() requests stand for an open place, the same ones CoverLadder::openRequestsLocked() counts.
                $shift->setAttribute('cover_names', $shift->coverRequests->take($shift->openPlaces())->map(fn ($request) => $request->releaserName())->unique()->values()->all());
                $shift->setAttribute('has_no_regular', $this->hasNoRegular($shift));
            })
            ->sortBy(fn (Shift $shift) => [$shift->cover_names === [] ? 1 : 0, $shift->starts_at->getTimestamp(), $shift->id])
            ->values();
    }

    /**
     * The shifts this person is on that start in the next `$days` days, cancelled ones included, each with `card_tag`: "Cancelled", "Every Thursday" for their regular slot, "Shadowing", or "One time".
     *
     * @return Collection<int, Shift>
     */
    public function nextShifts(User $user, int $days = 14): Collection
    {
        return $this->shiftsFrom($user, now()->addDays($days), true, true)->each(fn (Shift $shift) => $this->tag($shift, $user));
    }

    /**
     * Every shift from now on that this person is on (decision 16), cancelled ones included, with `card_tag` set the same way.
     *
     * @return Collection<int, Shift>
     */
    private function allFuture(User $user): Collection
    {
        return $this->shiftsFrom($user, null, true, true)->each(fn (Shift $shift) => $this->tag($shift, $user));
    }

    /**
     * The person's shifts, with their area, their own assignment and the slot's regulars: uncancelled ones only (what overlaps count), unless `$withCancelled`. With `$notStarted` the shifts have to start after now, otherwise they only have to end after now, so one running now still counts for overlaps. With no `$until` there's no end.
     *
     * @return Collection<int, Shift>
     */
    private function shiftsFrom(User $user, ?\DateTimeInterface $until, bool $notStarted, bool $withCancelled = false): Collection
    {
        $now = now();

        return Shift::query()->when(! $withCancelled, fn ($query) => $query->active())
            ->whereHas('assignments', fn ($assignments) => $assignments->where('user_id', $user->id))
            ->where($notStarted ? 'starts_at' : 'ends_at', '>', $now)
            ->when($until !== null, fn ($query) => $query->where('starts_at', '<=', $until))
            ->with([
                'area',
                'weeklySlot.regulars',
                'assignments' => fn ($assignments) => $assignments->where('user_id', $user->id),
            ])
            ->orderBy('starts_at')->orderBy('id')
            ->get();
    }

    private function tag(Shift $shift, User $user): void
    {
        $assignment = $shift->assignments->firstWhere('user_id', $user->id);
        $date = $shift->local_date->toDateString();
        $regular = $shift->weeklySlot?->regulars->first(fn (SlotRegular $regular) => $regular->user_id === $user->id && $regular->activeOn($date));

        $shift->setAttribute('card_tag', match (true) {
            $shift->cancelled_at !== null => 'Cancelled',
            $assignment?->type === AssignmentType::Shadow => 'Shadowing',
            $regular === null => 'One time',
            $regular->frequency === SlotFrequency::EveryOtherWeek => 'Every other '.$shift->weeklySlot->weekdayName(),
            default => 'Every '.$shift->weeklySlot->weekdayName(),
        });
    }

    /** The in-memory version of the check in TakeShift::choices(): a slot that isn't a rotation, with no regular who is current or still to start on this date. */
    private function hasNoRegular(Shift $shift): bool
    {
        $slot = $shift->weeklySlot;

        if ($slot === null || $slot->is_rotation) {
            return false;
        }

        $date = $shift->local_date->toDateString();

        return ! $slot->regulars->contains(fn (SlotRegular $regular) => $regular->ends_on === null
            || ($regular->ends_on->toDateString() >= $date && $regular->ends_on->toDateString() >= $regular->starts_on->toDateString()));
    }
}
