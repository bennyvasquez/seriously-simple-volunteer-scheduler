<?php

namespace App\Http\Controllers\Volunteer;

use App\Actions\Scheduling\ShiftAlreadyTaken;
use App\Actions\Scheduling\TakeShift;
use App\Cover\CoverEligibility;
use App\Cover\CoverFill;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\SlotFrequency;
use App\Enums\TakeHow;
use App\Http\Controllers\Controller;
use App\Models\CoverRequest;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Support\Input;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Taking an open shift (wireframes 3, 44 to 46). A cover request that can only be taken for its date asks no question ("Can you cover this one?"); a weekly slot date with no regular asks "How long do you want it?" with nothing picked (UX principle 2); any other open place is taken for its date. A shift with no open place says "Someone already took this one" and lists other open shifts, and so does a form posted after someone else took the last place. A guest is sent to log in and comes straight back (decision 2).
 */
class TakeController extends Controller
{
    public const PICK_HOW = 'Pick how long you want it.';

    public function show(Request $request, TakeShift $takeShift, HomeController $home, Shift $shift): View|RedirectResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('take', $shift);

        $shift->load(['area', 'weeklySlot', 'assignments.user']);
        $card = ReleaseController::card($shift);
        $refusal = CoverEligibility::refusal($user, $shift);
        $onIt = $shift->assignments->contains('user_id', $user->id);

        // Already filling a place (a double submit, or a second visit after taking it): that's "It's yours", not a refusal.
        if (self::stillAhead($shift) && $shift->assignments->contains(fn ($assignment) => $assignment->user_id === $user->id && $assignment->type === AssignmentType::Regular)) {
            return redirect()->route('shifts.taken', $shift);
        }

        if ($refusal !== null && ($onIt || $shift->cancelled_at !== null || $shift->starts_at->lessThanOrEqualTo(now()))) {
            return view('volunteer.take', ['mode' => 'refused', 'shift' => $shift, 'card' => $card, 'refusal' => $refusal]);
        }

        if ($shift->openPlaces() === 0) {
            return view('volunteer.take', [
                'mode' => 'taken',
                'shift' => $shift,
                'card' => $card,
                'open' => $home->openShifts($user)->reject(fn (Shift $other) => $other->id === $shift->id)->values(),
                'timezone' => Organization::current()->timezone,
            ]);
        }

        if ($refusal !== null) {
            return view('volunteer.take', ['mode' => 'refused', 'shift' => $shift, 'card' => $card, 'refusal' => $refusal]);
        }

        // The request taking this place would fill: the oldest open one (decision 9).
        $cover = CoverRequest::query()->with('releasedBy')->open()->where('shift_id', $shift->id)->orderBy('created_at')->orderBy('id')->first();
        $choices = $takeShift->choices($shift);
        $slot = $shift->weeklySlot;

        return view('volunteer.take', [
            'mode' => $cover !== null && count($choices) === 1 ? 'cover' : 'claim',
            'shift' => $shift,
            'card' => $card,
            'cover' => $cover,
            'releaser' => $cover === null ? null : ($cover->releasedBy === null || $cover->releasedBy->is_removed_person ? null : $cover->releasedBy->shortName()),
            'tookBack' => $cover !== null && $cover->released_by === $user->id,
            'choices' => $choices,
            'weekday' => $slot?->weekdayName(),
            'slotEveryOtherWeek' => $slot !== null && $slot->frequency === SlotFrequency::EveryOtherWeek,
            'openPlaces' => $shift->openPlaces(),
            'shadows' => $shift->assignments->where('type', AssignmentType::Shadow)->sortBy('id')->map(fn ($assignment) => $assignment->user->shortName())->values()->all(),
            'how' => Input::text($request->old('how', '')),
        ]);
    }

    public function store(Request $request, TakeShift $takeShift, Shift $shift): RedirectResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('take', $shift);

        $how = TakeHow::tryFrom(Input::string($request, 'how'));

        if ($how === null) {
            return redirect()->route('shifts.take', $shift)->withErrors(['how' => self::PICK_HOW]);
        }

        try {
            $takeShift->handle($user, $shift, $how);
        } catch (ShiftAlreadyTaken) {
            return redirect()->route('shifts.take', $shift);
        } catch (DomainException $e) {
            if ($shift->assignments()->where('user_id', $user->id)->where('type', AssignmentType::Regular)->exists()) {
                return redirect()->route('shifts.taken', $shift);
            }

            // Taking it every week leaves a full date out rather than saying it's taken, so a full shift is checked here and shown as taken.
            $fresh = $shift->fresh();

            if ($fresh->openPlaces() === 0 && ! $fresh->assignments()->where('user_id', $user->id)->exists()) {
                return redirect()->route('shifts.take', $shift);
            }

            return redirect()->route('shifts.take', $shift)->withInput(['how' => $how->value])->withErrors(['how' => $e->getMessage()]);
        }

        return redirect()->route('shifts.taken', $shift)->with('took', $how->value);
    }

    /** "It's yours" (wireframe 45), only for someone on a shift that's still ahead; anyone else goes to the take page. Who was told is worked out the way CoverFill::notices() picks them: the releaser of the request this person filled (unless they took it back) and the area's active leads, each only when they can be emailed. */
    public function taken(Request $request, Shift $shift): View|RedirectResponse
    {
        $user = $request->user();

        // A cancelled or started shift is never "yours" here; the take page says what happened to it.
        if (! self::stillAhead($shift) || ! $shift->assignments()->where('user_id', $user->id)->exists()) {
            return redirect()->route('shifts.take', $shift);
        }

        $shift->load('area', 'weeklySlot');
        $filled = CoverRequest::query()->with('releasedBy')->where('shift_id', $shift->id)->where('filled_by', $user->id)->orderByDesc('filled_at')->orderByDesc('id')->first();
        $told = [];
        $releaser = $filled?->releasedBy;

        if ($releaser !== null && $releaser->id !== $user->id && $releaser->canSignIn()) {
            $told[] = $releaser->shortName();
        }

        $leads = User::query()->people()->whereNull('deactivated_at')->whereKeyNot($user->id)
            ->whereHas('roles', fn ($roles) => $roles->where('role', Role::AreaLead->value)->where('area_id', $shift->area_id))
            ->orderBy('id')->get()->filter(fn (User $lead) => $lead->canSignIn());

        foreach ($leads as $lead) {
            if ($releaser?->id !== $lead->id) {
                $told[] = $lead->shortName().' ('.$shift->area->leadTitle().')';
            }
        }

        $took = TakeHow::tryFrom((string) $request->session()->get('took'));
        $regularLine = null;

        if ($shift->weeklySlot !== null && in_array($took, [TakeHow::EveryWeek, TakeHow::EveryOtherWeek], true)) {
            $regularLine = 'You\'re now the '.($took === TakeHow::EveryOtherWeek ? 'every other ' : '').$shift->weeklySlot->weekdayName().' regular, starting '.$shift->local_date->format('l, F j').'.';
        }

        return view('volunteer.taken', [
            'user' => $user,
            'card' => ReleaseController::card($shift),
            'shadows' => collect(CoverFill::shadows($shift))->map(fn (User $shadow) => $shadow->shortName())->all(),
            'told' => $told,
            'regularLine' => $regularLine,
        ]);
    }

    /** Not cancelled and not started yet. */
    private static function stillAhead(Shift $shift): bool
    {
        return $shift->cancelled_at === null && $shift->starts_at->greaterThan(now());
    }
}
