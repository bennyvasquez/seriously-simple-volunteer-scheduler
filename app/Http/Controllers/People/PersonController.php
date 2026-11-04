<?php

namespace App\Http\Controllers\People;

use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InvitePerson;
use App\Actions\Accounts\RevokeRole;
use App\ChangeLog\Describe;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * One person's page (screen 27): roles, login, inviting, turning off and the changes about them. Everyone who can see the person sees the page; only coordinators and admins who may manage them get the controls. Someone the viewer can't see, or who doesn't exist, is a 404.
 */
class PersonController extends Controller
{
    private const CHANGES_PER_PAGE = 10;

    public function show(Request $request, string $user): View
    {
        $viewer = $request->user();
        $person = $this->visible($viewer, $user);
        $canManage = $viewer->can('manage', $person);
        $timezone = Organization::current()->timezone;
        $status = $person->invitationStatus();
        $when = fn ($time) => $time ? $time->setTimezone($timezone)->format('M j, g:ia') : 'Never';

        $changes = null;

        if ($viewer->isCoordinator()) {
            $changes = ChangeLogEntry::query()->about($person)
                ->with(['affectedUser', 'subject'])
                ->orderByDesc('created_at')->orderByDesc('id')
                ->simplePaginate(self::CHANGES_PER_PAGE)->withQueryString();
        }

        return view('people.show', [
            'person' => $person,
            'firstName' => $person->firstName(),
            'roles' => $person->roleLabels(),
            'heldRoles' => $person->roles()->with('area')->get(),
            'addable' => $canManage ? $this->addableRoles($viewer, $person) : [],
            'canManage' => $canManage,
            'isAdminViewer' => $viewer->isActive() && $viewer->hasRole(Role::Admin),
            'status' => $status,
            'joined' => $person->email_verified_at?->setTimezone($timezone)->format('M j'),
            'lastLogin' => $when($person->last_login_at),
            'lastSeen' => $when($person->last_seen_at),
            'logsInWith' => $person->password !== null ? 'Password' : 'Email links',
            'needsTwoFactor' => $person->requiresTwoFactor(),
            'entries' => $changes?->getCollection()->map(fn (ChangeLogEntry $entry) => [
                'sentence' => Describe::entry($entry, $viewer),
                'changes' => Describe::changes($entry),
                'by' => $entry->isBySystem() ? 'The system' : ($entry->actor_name ?? ChangeLogEntry::REMOVED_PERSON),
                'at' => $entry->created_at->setTimezone($timezone)->format('M j, g:ia'),
            ]),
            'changesPager' => $changes,
            'changeLogUrl' => url('/change-log').'?'.http_build_query(['about' => $person->name]),
        ]);
    }

    public function addRole(Request $request, string $user, GrantRole $grantRole): RedirectResponse
    {
        $person = $this->manageable($request, $user);
        [$role, $area] = $this->chosenRole($request);

        abort_unless($request->user()->canGrant($role), 403);

        $grantRole->handle($request->user(), $person, $role, $area);

        return redirect()->route('people.show', $person)->with('status', $person->firstName().' is now '.$this->roleName($role, $area).'.');
    }

    public function removeRole(Request $request, string $user, RevokeRole $revokeRole): RedirectResponse
    {
        $person = $this->manageable($request, $user);
        [$role, $area] = $this->chosenRole($request);

        abort_unless($request->user()->canGrant($role), 403);

        $revokeRole->handle($request->user(), $person, $role, $area);

        return redirect()->route('people.show', $person)->with('status', $person->firstName().' is no longer '.$this->roleName($role, $area).'.');
    }

    public function invite(Request $request, string $user, InvitePerson $invitePerson): RedirectResponse
    {
        $person = $this->manageable($request, $user);

        try {
            $invitePerson->handle($request->user(), $person);
        } catch (DomainException $e) {
            return back()->withErrors(['invite' => $e->getMessage()]);
        }

        return redirect()->route('people.show', $person)->with('status', 'Invitation sent to '.$person->email.'.');
    }

    public function turnOff(Request $request, string $user, DeactivateUser $deactivateUser): RedirectResponse
    {
        $person = $this->manageable($request, $user);

        if ($person->isActive()) {
            $deactivateUser->handle($request->user(), $person);
        }

        return redirect()->route('people.show', $person)->with('status', $person->firstName().'\'s account is turned off.');
    }

    /** The person, or a 404 when the viewer can't see them or they don't exist. */
    private function visible(User $viewer, string $id): User
    {
        return $viewer->visiblePeople()->findOrFail($id);
    }

    /** The person, after checking the viewer may manage them. 404 comes before 403, so who exists is never revealed to someone who can't see them. */
    private function manageable(Request $request, string $id): User
    {
        $person = $this->visible($request->user(), $id);

        abort_unless($request->user()->can('manage', $person), 403);

        return $person;
    }

    /**
     * The role and area from the form. Either `role` is "admin", "coordinator", "area_lead" with an `area`, or the select's combined "area_lead:3".
     *
     * @return array{0: Role, 1: ?Area}
     */
    private function chosenRole(Request $request): array
    {
        $value = (string) $request->input('role', '');
        $areaId = $request->input('area');

        if (str_contains($value, ':')) {
            [$value, $areaId] = explode(':', $value, 2);
        }

        $role = Role::tryFrom($value);

        if ($role === null) {
            throw ValidationException::withMessages(['role' => 'Pick one of the roles.']);
        }

        if ($role !== Role::AreaLead) {
            return [$role, null];
        }

        $area = is_scalar($areaId) && ctype_digit((string) $areaId) ? Area::query()->find((int) $areaId) : null;

        if ($area === null) {
            throw ValidationException::withMessages(['role' => 'Pick which area they lead.']);
        }

        return [$role, $area];
    }

    private function roleName(Role $role, ?Area $area): string
    {
        return match ($role) {
            Role::Admin => 'an admin',
            Role::Coordinator => 'a coordinator',
            Role::AreaLead => $this->withArticle(mb_strtolower($area->leadTitle())).' for '.$area->name,
        };
    }

    private function withArticle(string $words): string
    {
        return (in_array($words[0], ['a', 'e', 'i', 'o', 'u'], true) ? 'an ' : 'a ').$words;
    }

    /** @return array<string, string> The "Add a role" choices this viewer can give this person, leaving out roles they already hold. */
    private function addableRoles(User $viewer, User $person): array
    {
        $held = $person->roles()->get();
        $options = [];

        foreach ([[Role::Admin, 'Admin'], [Role::Coordinator, 'Coordinator']] as [$role, $label]) {
            if ($viewer->canGrant($role) && ! $held->contains('role', $role)) {
                $options[$role->value] = $label;
            }
        }

        if ($viewer->canGrant(Role::AreaLead)) {
            foreach (Area::query()->orderBy('name')->get() as $area) {
                if (! $held->contains(fn ($assignment) => $assignment->role === Role::AreaLead && $assignment->area_id === $area->id)) {
                    $options['area_lead:'.$area->id] = $area->leadTitle().' for '.$area->name;
                }
            }
        }

        return $options;
    }
}
