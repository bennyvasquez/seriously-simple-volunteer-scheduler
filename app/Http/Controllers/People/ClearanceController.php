<?php

namespace App\Http\Controllers\People;

use App\Actions\Accounts\ClearPerson;
use App\Actions\Accounts\MoveBackToShadowing;
use App\Http\Controllers\Controller;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * "Clear for regular shifts" on the person page (screen 9, spec section 9), and moving someone back to shadowing (screen 10, a page of its own so it works without JavaScript). Someone the viewer can't see is a 404 before the 403, so who exists is never revealed.
 */
class ClearanceController extends Controller
{
    public function store(Request $request, string $user, ClearPerson $clearPerson): RedirectResponse
    {
        $person = $this->clearable($request, $user);

        try {
            $note = $request->input('note');
            // Anything but text (a posted array, say) is no note, the same as moveBack().
            $clearPerson->handle($request->user(), $person, is_string($note) ? $note : null);
        } catch (DomainException $e) {
            return back()->withInput($request->only('note'))->withErrors(['clear' => $e->getMessage()]);
        }

        return redirect()->route('people.show', $person)->with('status', $person->shortName().' is cleared for regular shifts.');
    }

    /** Screen 10. Someone who can't be moved back (they hold a role, or aren't cleared) goes back to their page with the reason. The keep or release choice is shown only when they have upcoming regular shifts or a regular slot. */
    public function confirmMoveBack(Request $request, string $user, MoveBackToShadowing $moveBack): View|RedirectResponse
    {
        $person = $this->clearable($request, $user);

        if (($refusal = $moveBack->refusal($person)) !== null) {
            return redirect()->route('people.show', $person)->withErrors(['clear' => $refusal]);
        }

        $preview = $moveBack->preview($person);
        $first = $person->shortName();
        $shifts = $preview['shifts'];
        $count = $shifts->count();
        $slots = Arr::join(MoveBackToShadowing::slotWords($preview['regulars']), '; ', ' and ');

        return view('people.move-back', [
            'person' => $person,
            'firstName' => $first,
            'hasChoice' => $count > 0 || $preview['regulars']->isNotEmpty(),
            'legend' => $count > 0 ? $first.' has '.$count.' upcoming regular '.($count === 1 ? 'shift' : 'shifts') : $first.' is a regular, but none of their shifts are on the calendar yet',
            'keepText' => $count > 0
                ? $first.' stays on '.Arr::join($shifts->map(fn ($shift) => $shift->local_date->format('D M j'))->all(), ', ', ' and ').'.'
                : $first.' stays the regular on '.$slots.'.',
            'releasesShifts' => $count > 0,
            'releaseRegularText' => $slots === '' ? null : $first.' stops being the regular on '.$slots.'.',
        ]);
    }

    public function moveBack(Request $request, string $user, MoveBackToShadowing $moveBack): RedirectResponse
    {
        $person = $this->clearable($request, $user);
        $choice = $request->input('existing');
        $preview = $moveBack->preview($person);

        // Asked again here: a choice is needed whenever the page would show one now.
        if (! in_array($choice, ['keep', 'release'], true) && ($preview['shifts']->isNotEmpty() || $preview['regulars']->isNotEmpty())) {
            throw ValidationException::withMessages(['existing' => 'Choose keep or release.']);
        }

        $note = $request->input('note');

        try {
            $released = $moveBack->handle($request->user(), $person, $choice === 'release', is_string($note) ? $note : null);
        } catch (DomainException $e) {
            return back()->withInput($request->only('existing', 'note'))->withErrors(['move_back' => $e->getMessage()]);
        }

        $count = $released->count();
        $status = $person->shortName().' is back to shadowing.'.($count === 0 ? '' : ' Their '.$count.' upcoming regular '.($count === 1 ? 'shift was' : 'shifts were').' released.');

        return redirect()->route('people.show', $person)->with('status', $status);
    }

    /** The person, after checking the viewer can see them (404) and may clear them or move them back (403). */
    private function clearable(Request $request, string $user): User
    {
        $person = $request->user()->visiblePeople()->findOrFail($user);

        abort_unless($request->user()->can('clear', $person), 403);

        return $person;
    }
}
