<?php

namespace App\Http\Controllers\People;

use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InvitePerson;
use App\Actions\Accounts\RemovePerson;
use App\Actions\Accounts\RevokeRole;
use App\Actions\Scheduling\ReleaseFutureSchedule;
use App\Calendar\TimeFormat;
use App\ChangeLog\Describe;
use App\Cover\ShadowProgress;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Volunteer\ReleaseController;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * One person's page (screen 27): roles, login, inviting, turning off, removing and the changes about them. Everyone who can see the person sees the page; only coordinators and admins who may manage them get the controls. Someone the viewer can't see, or who doesn't exist, is a 404.
 */
class PersonController extends Controller
{
    private const CHANGES_PER_PAGE = 10;

    public function show(Request $request, string $user): View
    {
        $viewer = $request->user();
        $person = $this->visible($viewer, $user);
        $canManage = $viewer->can('manage', $person);
        $organization = Organization::current();
        $timezone = $organization->timezone;
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
            'roleRows' => $this->roleRows($person),
            'addable' => $canManage ? $this->addableRoles($viewer, $person) : [],
            'canManage' => $canManage,
            'releases' => $canManage && $person->isActive() ? ReleaseFutureSchedule::counts($person) : null,
            'isAdminViewer' => $viewer->isActive() && $viewer->hasRole(Role::Admin),
            'status' => $status,
            'joined' => $person->email_verified_at?->setTimezone($timezone)->format('M j'),
            'lastLogin' => $when($person->last_login_at),
            'lastSeen' => $when($person->last_seen_at),
            'logsInWith' => $person->password !== null ? 'Password' : 'Email links',
            'entries' => $changes?->getCollection()->map(fn (ChangeLogEntry $entry) => [
                'sentence' => Describe::entry($entry, $viewer),
                'changes' => Describe::changes($entry),
                'by' => $entry->isBySystem() ? 'The system' : ($entry->actor_name ?? ChangeLogEntry::REMOVED_PERSON),
                'at' => $entry->created_at->setTimezone($timezone)->format('M j, g:ia'),
            ]),
            'changesPager' => $changes,
            'changeLogUrl' => route('change-log.index', ['about' => $person->name]),
            'clearance' => $this->clearance($viewer, $person, $organization),
        ]);
    }

    public function addRole(Request $request, string $user, GrantRole $grantRole): RedirectResponse
    {
        $person = $this->manageable($request, $user);
        [$role, $area] = $this->chosenRole($request);

        abort_unless($request->user()->canGrant($role), 403);

        $had = $person->roles()->where('role', $role->value)->where('area_id', $area?->id)->exists();
        $grantRole->handle($request->user(), $person, $role, $area);

        return redirect()->route('people.show', $person)->with('status', $had ? 'Nothing changed.' : $person->firstName().' is now '.Describe::roleName($role, $area).'.');
    }

    public function removeRole(Request $request, string $user, RevokeRole $revokeRole): RedirectResponse
    {
        $person = $this->manageable($request, $user);
        [$role, $area] = $this->chosenRole($request);

        abort_unless($request->user()->canGrant($role), 403);

        $held = $person->roles()->where('role', $role->value)->where('area_id', $area?->id)->exists();
        $revokeRole->handle($request->user(), $person, $role, $area);

        return redirect()->route('people.show', $person)->with('status', $held ? $person->firstName().' is no longer '.Describe::roleName($role, $area).'.' : 'Nothing changed.');
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
        $wasActive = $person->isActive();

        // Someone already turned off still has their future schedule cleared, so people turned off before that happened can be tidied.
        $released = $deactivateUser->handle($request->user(), $person);

        if (! $wasActive && $released['shifts'] === 0 && $released['regulars'] === 0) {
            return redirect()->route('people.show', $person)->with('status', 'Nothing changed.');
        }

        $status = $person->firstName().'\'s account is turned off.';

        if ($released['shifts'] > 0) {
            $status .= ' They were removed from '.$released['shifts'].' future '.($released['shifts'] === 1 ? 'shift' : 'shifts').'.';
        }

        return redirect()->route('people.show', $person)->with('status', $status);
    }

    /** The confirmation page for removing someone, saying when it removes them from future shifts and ends their regular slots first. */
    public function confirmRemove(Request $request, string $user): View
    {
        $person = $this->manageable($request, $user);

        return view('people.remove', [
            'person' => $person,
            'firstName' => $person->firstName(),
            'releases' => ReleaseFutureSchedule::counts($person),
        ]);
    }

    public function remove(Request $request, string $user, RemovePerson $removePerson): RedirectResponse
    {
        $person = $this->manageable($request, $user);
        // Kept from this request: once the row is gone there's nothing to read it from.
        $firstName = $person->firstName();
        $typed = trim((string) $request->input('confirm', ''));

        if (mb_strtolower($typed) !== mb_strtolower($firstName)) {
            throw ValidationException::withMessages(['confirm' => 'Type '.$firstName.' to confirm.']);
        }

        try {
            $removePerson->handle($request->user(), $person);
        } catch (DomainException $e) {
            return back()->withErrors(['remove' => $e->getMessage()]);
        }

        return redirect()->route('people.index')->with('status', $firstName.' was removed.');
    }

    /**
     * The "Shadowing and clearance" panel (screen 9), for everyone who can see the page (spec section 13). `canClear` is whether to show the "Clear for regular shifts" form: coordinators can clear someone before they're ready (section 9), but not someone cleared or turned off.
     *
     * @return array{clearedSince: ?string, done: int, required: int, ready: bool, history: Collection<int, array{text: string, status: string}>, canClear: bool}
     */
    private function clearance(User $viewer, User $person, Organization $organization): array
    {
        $timezone = $organization->timezone;
        $done = ShadowProgress::done($person);
        $required = (int) $organization->required_shadow_shifts;

        $history = ShadowProgress::history($person)->load('shift.assignments.user')->map(function (Assignment $shadow) use ($person, $timezone) {
            $shift = $shadow->shift;
            $starts = $shift->starts_at->setTimezone($timezone);
            $regulars = $shift->assignments
                ->filter(fn (Assignment $assignment) => $assignment->type === AssignmentType::Regular && $assignment->user_id !== $person->id)
                ->map(fn (Assignment $assignment) => $assignment->user->firstName())
                ->values()->all();

            return [
                'text' => $starts->format('D M j').', '.$shift->area->name.' '.TimeFormat::range($starts, $shift->ends_at->setTimezone($timezone)).($regulars === [] ? '' : ', with '.ReleaseController::names($regulars)).'.',
                'status' => match (true) {
                    $shift->cancelled_at !== null => 'Cancelled',
                    $shadow->no_show_at !== null => 'No-show',
                    default => 'Done',
                },
            ];
        });

        return [
            'clearedSince' => $person->cleared_at?->setTimezone($timezone)->format('M j'),
            'done' => $done,
            'required' => $required,
            'ready' => $done >= $required,
            'history' => $history,
            'canClear' => ! $person->isCleared() && $person->isActive() && $viewer->can('clear', $person),
            // Holding a role keeps someone cleared (decision 4), so they're never offered it.
            'canMoveBack' => $person->isCleared() && ! $person->roles()->exists() && $viewer->can('clear', $person),
        ];
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

    /** @return list<array{label: string, role: Role, area_id: ?int}> The person's roles in the order roleLabels() gives, each with the role it removes. */
    private function roleRows(User $person): array
    {
        $held = $person->roles()->with('area')->get();
        $ordered = collect([Role::Admin, Role::Coordinator])
            ->flatMap(fn (Role $role) => $held->where('role', $role)->all())
            ->concat($held->where('role', Role::AreaLead)->sortBy(fn ($assignment) => mb_strtolower($assignment->area?->name ?? ''))->all())
            ->values();

        $labels = $person->roleLabels();

        return $ordered->map(fn ($assignment, $i) => ['label' => $labels[$i], 'role' => $assignment->role, 'area_id' => $assignment->area_id])->all();
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
