<?php

namespace App\Http\Controllers\Calendar;

use App\Calendar\CalendarMonth;
use App\Calendar\CalendarWeek;
use App\Calendar\NeedsAttention;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * The calendar's read-only views, open to every active person: week (screen 30), day, and month (screen 33). The phone list (screen 34) is part of the week and day pages. Access is checked by the route's can:view-schedule middleware; the editing routes still need a role (can:viewAny,App\Models\Area).
 */
class CalendarController extends Controller
{
    public function week(Request $request): View
    {
        $organization = Organization::current();
        $date = $this->dateAsked($request, $organization);
        $areas = Area::query()->orderBy('id')->get();
        $shown = $this->shownAreaIds($request, $areas);
        $week = CalendarWeek::for($date, $organization, $shown);
        $days = $week->days();

        return view('calendar.week', $this->gridData($request, $organization, $week, $areas, $shown) + [
            'view' => 'week',
            'date' => $date,
            'previous' => CarbonImmutable::parse($days[0])->subWeek()->toDateString(),
            'next' => CarbonImmutable::parse($days[0])->addWeek()->toDateString(),
        ]);
    }

    public function day(Request $request): View
    {
        $organization = Organization::current();
        $date = $this->dateAsked($request, $organization);
        $areas = Area::query()->orderBy('id')->get();
        $shown = $this->shownAreaIds($request, $areas);
        $week = CalendarWeek::forDay($date, $organization, $shown);

        return view('calendar.week', $this->gridData($request, $organization, $week, $areas, $shown) + [
            'view' => 'day',
            'date' => $date,
            'previous' => $date->subDay()->toDateString(),
            'next' => $date->addDay()->toDateString(),
        ]);
    }

    public function month(Request $request): View
    {
        $organization = Organization::current();
        $month = $this->monthAsked($request, $organization);
        $areas = Area::query()->orderBy('id')->get();
        $shown = $this->shownAreaIds($request, $areas);
        $today = $organization->today();

        return view('calendar.month', [
            'organization' => $organization,
            'calendar' => CalendarMonth::for($month->format('Y-m'), $organization, $areas->whereIn('id', $shown)->values()),
            'areaQuery' => $this->areaQuery($request, $shown),
            'today' => $today->toDateString(),
            // The day and week buttons open on today when it's in this month, and on the 1st otherwise.
            'date' => $month->format('Y-m') === $today->format('Y-m') ? $today->toDateString() : $month->toDateString(),
            'previous' => $month->subMonth()->format('Y-m'),
            'next' => $month->addMonth()->format('Y-m'),
            'nothingShown' => $shown === [],
            'tools' => $request->user()->canViewCalendar(),
            'canClose' => $areas->contains(fn (Area $area) => Gate::forUser($request->user())->allows('close', $area)),
            'closableAreaIds' => $this->closableAreaIds($request, $areas),
        ]);
    }

    /** @param  Collection<int, Area>  $areas */
    private function gridData(Request $request, Organization $organization, CalendarWeek $week, Collection $areas, array $shown): array
    {
        $areaQuery = $this->areaQuery($request, $shown);
        $user = $request->user();
        $tools = $user->canViewCalendar();

        return [
            'organization' => $organization,
            'week' => $week,
            'areas' => $areas,
            'shown' => $shown,
            'areaQuery' => $areaQuery,
            'tools' => $tools,
            'needsAttention' => $tools ? NeedsAttention::for($organization, $shown, $areaQuery, $user) : collect(),
            'nextShift' => $tools ? null : $this->nextShift($user),
            'hasOwnShift' => $week->hasShiftOf($user),
            'viewer' => $user,
            'now' => CarbonImmutable::now(),
            'today' => $organization->today()->toDateString(),
            'nothingShown' => $shown === [],
            'canClose' => $areas->contains(fn (Area $area) => Gate::forUser($request->user())->allows('close', $area)),
            'closableAreaIds' => $this->closableAreaIds($request, $areas),
        ];
    }

    /** The person's next uncancelled shift that hasn't started, in any area, as a regular or a shadow, with who else is on it. */
    private function nextShift(User $user): ?Shift
    {
        return Shift::query()->active()
            ->whereHas('assignments', fn ($assignments) => $assignments->where('user_id', $user->id))
            ->where('starts_at', '>', now())
            ->with(['area', 'assignments.user'])
            ->orderBy('starts_at')->orderBy('id')
            ->first();
    }

    /**
     * The areas whose closures this person can change, so a closure label is a link to its edit page for them and plain text for everyone else.
     *
     * @param  Collection<int, Area>  $areas
     * @return list<int>
     */
    private function closableAreaIds(Request $request, Collection $areas): array
    {
        return $areas->filter(fn (Area $area) => Gate::forUser($request->user())->allows('close', $area))->values()->modelKeys();
    }

    /** The date asked for, as a plain date, or today in the organization's time zone when it's missing or not a real date. */
    private function dateAsked(Request $request, Organization $organization): CarbonImmutable
    {
        $value = $request->query('date');

        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
            if ($date !== false && $date->toDateString() === $value) {
                return $date;
            }
        }

        return CarbonImmutable::parse($organization->today()->toDateString(), 'UTC');
    }

    private function monthAsked(Request $request, Organization $organization): CarbonImmutable
    {
        $value = $request->query('month');

        if (is_string($value) && preg_match('/^\d{4}-\d{2}$/', $value) === 1) {
            $month = CarbonImmutable::createFromFormat('!Y-m', $value, 'UTC');
            if ($month !== false && $month->format('Y-m') === $value) {
                return $month;
            }
        }

        return CarbonImmutable::parse($organization->today()->format('Y-m').'-01', 'UTC');
    }

    /**
     * Every area when the query has no areas[], otherwise the ticked ones that exist. The sidebar form always sends an empty areas[] entry, so unticking every box shows nothing instead of everything.
     *
     * @param  Collection<int, Area>  $areas
     * @return list<int>
     */
    private function shownAreaIds(Request $request, Collection $areas): array
    {
        $asked = $request->query('areas');

        if (! is_array($asked)) {
            return $areas->modelKeys();
        }

        $ids = array_map('intval', array_filter($asked, fn ($id) => is_scalar($id) && ctype_digit((string) $id)));

        return array_values(array_intersect($areas->modelKeys(), $ids));
    }

    /** The areas[] part of the query for links that keep the current filter, or nothing when every area is shown. */
    private function areaQuery(Request $request, array $shown): array
    {
        if (! is_array($request->query('areas'))) {
            return [];
        }

        return ['areas' => $shown === [] ? [''] : $shown];
    }
}
