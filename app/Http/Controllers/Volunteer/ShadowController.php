<?php

namespace App\Http\Controllers\Volunteer;

use App\Actions\Scheduling\TakeShadowSpot;
use App\Enums\AssignmentType;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Shift;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Signing up for a shadow spot ("Shadow this shift", spec section 9). The page shows the shift and who's on it with one button, and lands on Home with "You're shadowing {Area} on {date}." When the person can't sign up, the page says why and, for someone not cleared yet, lists other open shadow spots, the way "Someone already took this one" lists other open shifts. A form posted after the spot went comes back to that explanation, and pressing it twice lands on Home as if once.
 */
class ShadowController extends Controller
{
    public function show(Request $request, HomeController $home, Shift $shift): View
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('take', $shift);

        $shift->load(['area', 'assignments' => fn ($assignments) => $assignments->orderBy('id'), 'assignments.user']);
        $refusal = TakeShadowSpot::refusal($user, $shift);

        return view('volunteer.shadow', [
            'shift' => $shift,
            'card' => ReleaseController::card($shift),
            'refusal' => $refusal,
            'shadowingWith' => ReleaseController::names($shift->assignments->where('type', AssignmentType::Regular)->map(fn ($assignment) => $assignment->user->firstName())->values()->all()),
            'spots' => $refusal !== null && ! $user->isCleared()
                ? $home->openShadowSpots($user)->reject(fn (Shift $other) => $other->id === $shift->id)->values()
                : null,
            'timezone' => Organization::current()->timezone,
        ]);
    }

    public function store(Request $request, TakeShadowSpot $takeShadowSpot, Shift $shift): RedirectResponse
    {
        $user = $request->user();
        Gate::forUser($user)->authorize('take', $shift);

        try {
            $takeShadowSpot->handle($user, $shift);
        } catch (DomainException) {
            // Pressed twice: the first press signed them up, so this one lands on Home the same way.
            if ($shift->cancelled_at === null && $shift->starts_at->greaterThan(now()) && $shift->assignments()->where('user_id', $user->id)->where('type', AssignmentType::Shadow)->exists()) {
                return $this->signedUp($shift);
            }

            return redirect()->route('shifts.shadow', $shift);
        }

        return $this->signedUp($shift);
    }

    private function signedUp(Shift $shift): RedirectResponse
    {
        $card = ReleaseController::card($shift);

        return redirect()->route('home')->with('status', 'You\'re shadowing '.$card['area'].' on '.$card['date'].'.');
    }
}
