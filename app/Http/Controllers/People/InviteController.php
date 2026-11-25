<?php

namespace App\Http\Controllers\People;

use App\Actions\Accounts\CreatePerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InviteEveryone;
use App\Actions\Accounts\InvitePerson;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\User;
use App\Support\PersonName;
use DomainException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/** Inviting someone (screen 26) and inviting everyone who isn't invited yet. The routes are limited to people who can invite. */
class InviteController extends Controller
{
    public function create(Request $request): View
    {
        return view('people.invite', ['options' => $this->startOptions($request->user())]);
    }

    public function store(Request $request, CreatePerson $createPerson, GrantRole $grantRole, InvitePerson $invitePerson): RedirectResponse
    {
        $viewer = $request->user();
        $options = $this->startOptions($viewer);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255', fn ($attribute, $value, $fail) => is_string($value) && PersonName::problem($value) === null ? null : $fail(PersonName::REFUSAL)],
            'email' => ['required', 'email:rfc', 'max:255'],
            'starts_as' => ['required', 'in:'.implode(',', array_keys($options))],
        ], [
            'name.required' => 'Enter their name.',
            'email.required' => 'Enter their email address.',
            'email.*' => 'Enter an email address like name@example.org.',
            'starts_as.*' => 'Pick one of the choices.',
        ]);

        if (User::isUnusableEmail($data['email'])) {
            throw ValidationException::withMessages(['email' => 'That email address can\'t be used.']);
        }

        try {
            // One transaction, so a failed grant leaves no half-made person. The email is deferred until after the response.
            $person = DB::transaction(function () use ($viewer, $data, $createPerson, $grantRole, $invitePerson) {
                $person = $createPerson->handle($viewer, $data['name'], $data['email']);

                if (str_starts_with($data['starts_as'], 'area_lead:')) {
                    $grantRole->handle($viewer, $person, Role::AreaLead, Area::query()->findOrFail((int) Str::after($data['starts_as'], ':')));
                }

                $invitePerson->handle($viewer, $person);

                return $person;
            });
        } catch (DomainException $e) {
            return back()->withErrors(['email' => $e->getMessage()])->withInput();
        } catch (UniqueConstraintViolationException) {
            // Two submissions of the same address at once both passed the check; the unique index stopped the second.
            return back()->withErrors(['email' => 'Someone with that email address already has an account.'])->withInput();
        }

        return redirect()->route('people.index')->with('status', 'Invitation sent to '.$person->email.'.');
    }

    public function everyone(): View
    {
        return view('people.invite-everyone', ['count' => InviteEveryone::waitingToBeInvited()->count()]);
    }

    public function sendToEveryone(Request $request, InviteEveryone $inviteEveryone): RedirectResponse
    {
        $count = $inviteEveryone->handle($request->user());

        return redirect()->route('people.index')->with('status', $count === 0 ? 'Nobody was waiting to be invited.' : 'Sent '.$count.' '.Str::plural('invitation', $count).'.');
    }

    /** @return array<string, string> The "They start as" choices this person can give: Volunteer, then one per area they can grant lead for. */
    private function startOptions(User $viewer): array
    {
        $options = ['volunteer' => 'Volunteer'];

        if ($viewer->canGrant(Role::AreaLead)) {
            foreach (Area::query()->orderBy('name')->get() as $area) {
                $options['area_lead:'.$area->id] = $area->leadTitle().' for '.$area->name;
            }
        }

        return $options;
    }
}
