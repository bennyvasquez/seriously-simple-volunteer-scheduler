<?php

namespace App\Http\Controllers;

use App\Actions\Accounts\ResetTwoFactor;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * An admin's view of one person's login and security, and the two-factor reset for someone who has lost both their phone and their recovery codes (spec section 13). Admins only: nothing about the person is shown to anyone else.
 */
class PersonSecurityController extends Controller
{
    public function show(Request $request, string $user): View
    {
        $this->authorizeAdmin($request);
        $user = User::query()->findOrFail($user);

        $user->load('roles.area');

        return view('people.security', [
            'person' => $user,
            'firstName' => $user->firstName(),
            'roles' => $user->roles->map(fn ($assignment) => match ($assignment->role) {
                Role::Admin => 'Admin',
                Role::Coordinator => 'Coordinator',
                Role::AreaLead => 'Area lead'.($assignment->area ? ': '.$assignment->area->name : ''),
            })->all(),
            'timezone' => Organization::query()->value('timezone') ?? 'UTC',
            'isSelf' => $request->user()->is($user),
        ]);
    }

    public function reset(Request $request, string $user, ResetTwoFactor $resetTwoFactor): RedirectResponse
    {
        // The admin check comes before the lookup, so a non-admin gets the same 403 for a person who exists and one who doesn't.
        $this->authorizeAdmin($request);
        $user = User::query()->findOrFail($user);

        $request->validate(['identity_checked' => ['accepted']], ['identity_checked.accepted' => 'Tick the box once you\'ve checked it\'s really them.']);

        try {
            $resetTwoFactor->handle($request->user(), $user);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['identity_checked' => $e->getMessage()]);
        }

        return redirect()->route('people.security', $user)->with('status', $user->firstName().'\'s second step is reset. They\'ll set it up again next time they log in.');
    }

    private function authorizeAdmin(Request $request): void
    {
        $admin = $request->user();

        abort_unless($admin->isActive() && $admin->hasRole(Role::Admin), 403);
    }
}
