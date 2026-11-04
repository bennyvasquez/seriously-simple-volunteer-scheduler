<?php

namespace App\Http\Controllers\People;

use App\Actions\Accounts\InviteEveryone;
use App\Enums\InvitationStatus;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * The people list (screens 24 and 25). Coordinators and admins see everyone; an area lead sees only the people the model says they can see, and searches by name only (spec section 13).
 */
class PeopleController extends Controller
{
    public const PER_PAGE = 50;

    public function index(Request $request): View
    {
        $viewer = $request->user();
        $isCoordinator = $viewer->isCoordinator();

        $q = trim((string) $request->query('q', ''));
        $role = $this->choice($request->query('role'), ['volunteer', ...array_column(Role::cases(), 'value')]);
        $invitation = InvitationStatus::tryFrom((string) $request->query('invitation', ''));
        $show = $this->choice($request->query('show'), ['active', 'off', 'all']) ?? 'active';
        $area = Area::query()->find(is_scalar($request->query('area')) ? (int) $request->query('area') : 0);

        $waiting = fn ($links) => $links->where('purpose', LoginLinkPurpose::Invitation->value)->whereNull('used_at')->where('expires_at', '>', now());

        $people = $viewer->visiblePeople()
            ->when($show === 'active', fn (Builder $query) => $query->whereNull('deactivated_at'))
            ->when($show === 'off', fn (Builder $query) => $query->whereNotNull('deactivated_at'))
            ->when($q !== '', function (Builder $query) use ($q, $isCoordinator) {
                $like = '%'.addcslashes($q, '%_\\').'%';
                $query->where(fn (Builder $match) => $match->where('name', 'like', $like)->when($isCoordinator, fn (Builder $match) => $match->orWhere('email', 'like', $like)));
            })
            ->when($role === 'volunteer', fn (Builder $query) => $query->whereDoesntHave('roles'))
            ->when($role !== null && $role !== 'volunteer', fn (Builder $query) => $query->whereHas('roles', fn ($roles) => $roles->where('role', $role)))
            ->when($area !== null, fn (Builder $query) => $query->where(fn (Builder $match) => $match
                ->whereHas('roles', fn ($roles) => $roles->where('role', Role::AreaLead->value)->where('area_id', $area->id))
                ->orWhereHas('assignments.shift', fn ($shift) => $shift->where('area_id', $area->id))
                ->orWhereHas('regulars.weeklySlot', fn ($slot) => $slot->where('area_id', $area->id))))
            ->when($invitation !== null, fn (Builder $query) => $query->withInvitationStatus($invitation))
            ->with('roles.area')
            ->withExists(['loginLinks as has_waiting_link' => $waiting])
            ->withMax(['loginLinks as last_invitation_at' => fn ($links) => $links->where('purpose', LoginLinkPurpose::Invitation->value)], 'created_at')
            ->orderBy('name')->orderBy('id')
            ->simplePaginate(self::PER_PAGE)->withQueryString();

        $timezone = Organization::current()->timezone;

        $rows = $people->getCollection()->map(fn (User $person) => [
            'person' => $person,
            'roles' => $person->roleLabels(),
            'status' => $person->invitationStatus((bool) $person->has_waiting_link),
            'sent' => $person->last_invitation_at ? $this->shortDate($person->last_invitation_at, $timezone) : null,
            'seen' => $this->when($person->last_seen_at, $timezone),
            'login' => $this->when($person->last_login_at, $timezone),
        ]);

        $counts = $this->counts($viewer);

        return view('people.index', [
            'people' => $people,
            'rows' => $rows,
            'counts' => $counts,
            'summary' => $this->summary($counts),
            'canInvite' => $viewer->can('invite', User::class),
            'notInvitedCount' => $isCoordinator ? InviteEveryone::waitingToBeInvited()->count() : 0,
            'areas' => Area::query()->orderBy('name')->get(),
            'filters' => ['q' => $q, 'role' => $role, 'area' => $area?->id, 'invitation' => $invitation?->value, 'show' => $show],
            'invitationStatuses' => InvitationStatus::cases(),
            'isCoordinator' => $isCoordinator,
            'ledAreaNames' => $viewer->roles()->with('area')->get()->where('role', Role::AreaLead)->pluck('area.name')->filter()->sort()->values()->all(),
        ]);
    }

    /** @return array{people: int, waiting: int, notInvited: int} Counts over the active people this viewer can see. */
    private function counts(User $viewer): array
    {
        $active = fn () => $viewer->visiblePeople()->whereNull('deactivated_at');

        return [
            'people' => $active()->count(),
            'waiting' => $active()->withInvitationStatus(InvitationStatus::Waiting)->count(),
            'notInvited' => $active()->withInvitationStatus(InvitationStatus::NotInvited)->count(),
        ];
    }

    /** "48 people, 3 invitations waiting, 20 not invited yet", leaving out parts that are zero. */
    private function summary(array $counts): string
    {
        $parts = [$counts['people'].' '.($counts['people'] === 1 ? 'person' : 'people')];

        if ($counts['waiting'] > 0) {
            $parts[] = $counts['waiting'].' '.($counts['waiting'] === 1 ? 'invitation' : 'invitations').' waiting';
        }

        if ($counts['notInvited'] > 0) {
            $parts[] = $counts['notInvited'].' not invited yet';
        }

        return implode(', ', $parts);
    }

    /** @param  list<string>  $allowed */
    private function choice(mixed $value, array $allowed): ?string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : null;
    }

    private function shortDate(CarbonInterface|string $date, string $timezone): string
    {
        return CarbonImmutable::parse($date)->setTimezone($timezone)->format('M j');
    }

    /** "Today, 9:12am", "Yesterday", "Oct 12", or "Never". */
    private function when(?CarbonInterface $time, string $timezone): string
    {
        if ($time === null) {
            return 'Never';
        }

        $local = CarbonImmutable::instance($time)->setTimezone($timezone);
        $today = CarbonImmutable::now($timezone)->startOfDay();

        return match (true) {
            $local->isSameDay($today) => 'Today, '.$local->format('g:ia'),
            $local->isSameDay($today->subDay()) => 'Yesterday',
            default => $local->format('M j'),
        };
    }
}
