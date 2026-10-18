<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CreateClosure;
use App\Calendar\PersonSearch;
use App\Calendar\TimeFormat;
use App\Enums\AssignmentType;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * Adding a closure (screen 37): pick areas, days and a reason, see what it cancels, and close. Only coordinators and admins can close an area; the route's can:viewAny middleware lets leads reach the calendar, and this controller refuses them.
 */
class ClosureController extends Controller
{
    use KeepsAreaFilter;

    /** The form, with "What this cancels" built from the areas and days in the query (area, from, to) or the values just posted. */
    public function create(Request $request): View
    {
        $closable = $this->closableAreas($request);
        $old = $request->old();

        $values = [
            'area' => $this->ids($old === [] ? $request->query('area') : ($old['area'] ?? [])),
            'from' => $this->stringValue($old['from'] ?? $request->query('from')),
            'to' => $this->stringValue($old['to'] ?? $request->query('to')),
            'reason' => $this->stringValue($old['reason'] ?? ''),
        ];
        $chosen = $closable->whereIn('id', $values['area'])->values();
        $values['area'] = $chosen->modelKeys();

        return view('calendar.closure', [
            'areas' => $closable,
            'values' => $values,
            'chosen' => $chosen,
            'preview' => $this->preview($request->user(), $chosen, $values['from'], $values['to']),
            'areaQuery' => $this->areaQuery($request),
            'backDate' => $this->isDate($values['from']) ? $values['from'] : Organization::current()->today()->toDateString(),
        ]);
    }

    public function store(Request $request, CreateClosure $createClosure): RedirectResponse
    {
        $this->closableAreas($request);
        $areaQuery = $this->areaQuery($request);
        $values = [
            'area' => $this->ids($request->input('area')),
            'from' => $this->stringValue($request->input('from')),
            'to' => $this->stringValue($request->input('to')),
        ];

        if ($request->filled('refresh')) {
            return redirect()->route('calendar.closures.create', array_filter($values) + $areaQuery)->withInput();
        }

        $request->validate([
            'area' => ['required', 'array'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason' => ['required', 'string', 'max:200'],
        ], [
            'area.*' => 'Pick at least one area.',
            'from.*' => 'Pick the first day closed.',
            'to.after_or_equal' => 'The last day closed has to be on or after the first day.',
            'to.*' => 'Pick the last day closed.',
            'reason.required' => 'Say why it\'s closed, so volunteers know.',
            'reason.max' => 'Keep it to 200 characters or fewer.',
            'reason.*' => 'Say why it\'s closed, so volunteers know.',
        ]);

        try {
            $closures = $createClosure->handle($request->user(), $values['area'], $values['from'], $values['to'], (string) $request->input('reason'));
        } catch (DomainException $e) {
            return redirect()->route('calendar.closures.create', $areaQuery)->withErrors(['closure' => $e->getMessage()])->withInput();
        }

        $shifts = Shift::query()->whereIn('area_closure_id', $closures->modelKeys())->with('assignments.user')->get();
        $emailed = $this->activePeople($shifts)->count();
        // The action stores a closure that started before today from today, so read the days back from what it saved.
        $first = $closures->first()->starts_on;
        $last = $closures->first()->ends_on;
        $names = $this->names($closures->pluck('area'));
        $cancelled = $shifts->isEmpty() ? 'No shifts were on the calendar for those days.' : 'Cancelled '.$shifts->count().' '.Str::plural('shift', $shifts->count()).' and '.match ($emailed) {
            0 => 'nobody was on them.',
            1 => 'emailed 1 person.',
            default => 'emailed '.$emailed.' people.',
        };

        if ($first->toDateString() !== $values['from']) {
            $verb = $closures->count() === 1 ? ' is closed ' : ' are closed ';
            $days = $first->equalTo($last) ? 'today, '.$first->format('l, F j') : 'from today, '.$first->format('l, F j').', through '.$last->format('l, F j');
            $status = $names.$verb.$days.'. '.$cancelled;
        } else {
            $days = $first->equalTo($last) ? 'on '.$first->format('l, F j') : 'from '.$first->format('l, F j').' to '.$last->format('l, F j');
            $status = 'Closed '.$names.' '.$days.'. '.$cancelled;
        }

        return redirect()->route('calendar.week', ['date' => $first->toDateString()] + $areaQuery)->with('status', $status);
    }

    /**
     * The shifts the closure would cancel, with who is on each, the button text and what happens to the people on them. Null until areas and days are picked.
     *
     * @param  Collection<int, Area>  $chosen
     * @return array{rows: list<array{when: string, people: string}>, count: int, people: int, button: string, already: int}|null
     */
    private function preview(User $viewer, Collection $chosen, string $from, string $to): ?array
    {
        if ($chosen->isEmpty() || ! $this->isDate($from) || ! $this->isDate($to) || $to < $from) {
            return null;
        }

        $timezone = Organization::current()->timezone;
        $inRange = Shift::query()
            ->whereIn('area_id', $chosen->modelKeys())
            ->whereBetween('local_date', [$from, $to])
            ->where('starts_at', '>', now())
            ->with(['area', 'assignments.user'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $shifts = $inRange->whereNull('cancelled_at')->values();
        $nameOf = [];

        $rows = $shifts->map(function (Shift $shift) use ($viewer, $chosen, $timezone, &$nameOf) {
            $start = $shift->starts_at->setTimezone($timezone);
            $when = $start->format('D M j').', '.TimeFormat::range($start, $shift->ends_at->setTimezone($timezone));

            // Several areas: each shift names its area, so they can be told apart without color.
            if ($chosen->count() > 1) {
                $when .= ', '.$shift->area->name;
            }

            // Full names only for people the viewer may see, as everywhere else (spec section 13).
            $name = function (Assignment $assignment) use ($viewer, &$nameOf) {
                return $nameOf[$assignment->user_id] ??= PersonSearch::displayName($viewer, $assignment->user);
            };
            $assignments = $shift->assignments->sortBy('id');
            $names = [
                ...$assignments->where('type', AssignmentType::Regular)->map($name)->all(),
                ...$assignments->where('type', AssignmentType::Shadow)->map(fn (Assignment $assignment) => $name($assignment).' shadowing')->all(),
            ];

            return ['when' => $when, 'people' => $names === [] ? 'Open' : implode(', ', $names)];
        })->all();

        $count = $shifts->count();
        $button = 'Close '.$this->names($chosen).($count === 0 ? '' : ' and cancel '.$count.' '.Str::plural('shift', $count));

        return [
            'rows' => $rows,
            'count' => $count,
            'people' => $this->activePeople($shifts)->count(),
            'button' => $button,
            'already' => $inRange->whereNotNull('cancelled_at')->count(),
        ];
    }

    /**
     * @param  Collection<int, Shift>  $shifts  With assignments.user loaded.
     * @return Collection<int, User> Each active person on the shifts once.
     */
    private function activePeople(Collection $shifts): Collection
    {
        return $shifts->flatMap(fn (Shift $shift) => $shift->assignments->pluck('user'))
            ->filter(fn (User $user) => $user->isActive())
            ->unique('id')
            ->values();
    }

    /** "Front desk", "Front desk and Hosted events" or "Front desk, Hosted events and Outreach events". */
    private function names(Collection $areas): string
    {
        return $areas->pluck('name')->join(', ', ' and ');
    }

    /** @return Collection<int, Area> The areas this person can close, in sidebar order. 403 when there are none. */
    private function closableAreas(Request $request): Collection
    {
        $user = $request->user();
        $areas = Area::query()->orderBy('id')->get()->filter(fn (Area $area) => Gate::forUser($user)->allows('close', $area))->values();

        abort_if($areas->isEmpty(), 403);

        return $areas;
    }

    /** @return list<int> Whole-number ids from a single value or a list. */
    private function ids(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];

        return array_values(array_unique(array_map('intval', array_filter($values, fn ($id) => is_scalar($id) && ctype_digit((string) $id)))));
    }

    private function stringValue(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    private function isDate(string $value): bool
    {
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date !== false && $date->toDateString() === $value;
    }
}
