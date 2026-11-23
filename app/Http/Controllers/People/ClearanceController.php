<?php

namespace App\Http\Controllers\People;

use App\Actions\Accounts\ClearPerson;
use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Clear for regular shifts" on the person page (screen 9, spec section 9). Someone the viewer can't see is a 404 before the 403, so who exists is never revealed.
 */
class ClearanceController extends Controller
{
    public function store(Request $request, string $user, ClearPerson $clearPerson): RedirectResponse
    {
        $person = $request->user()->visiblePeople()->findOrFail($user);

        abort_unless($request->user()->can('clear', $person), 403);

        try {
            $clearPerson->handle($request->user(), $person, (string) $request->input('note', ''));
        } catch (DomainException $e) {
            return back()->withInput($request->only('note'))->withErrors(['clear' => $e->getMessage()]);
        }

        return redirect()->route('people.show', $person)->with('status', $person->firstName().' is cleared for regular shifts.');
    }
}
