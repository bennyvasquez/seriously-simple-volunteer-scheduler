<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CreateClosure;
use App\Actions\Scheduling\RemoveClosure;
use App\Actions\Scheduling\UpdateClosure;
use App\Calendar\PersonSearch;
use App\Calendar\TimeFormat;
use App\Enums\AssignmentType;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AreaClosure;
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
 * Adding a closure (screen 37): pick areas, days and a reason, see what it cancels, and close. Changing one reuses the same page for its one area, with "What this changes" listing the shifts it would cancel and bring back, and removing one is a confirm step on that page (?remove=1). Only coordinators and admins can close an area; the route's can:viewAny middleware lets leads reach the calendar, and this controller refuses them.
 */
class ClosureController extends Controller
{
    use KeepsAreaFilter;

    /** The validation messages, the same for adding and changing a closure. */
    private const MESSAGES = [
        'area.*' => 'Pick at least one area.',
        'from.*' => 'Pick the first day closed.',
        'to.after_or_equal' => 'The last day closed has to be on or after the first day.',
        'to.*' => 'Pick the last day closed.',
        'reason.required' => 'Say why it\'s closed, so volunteers know.',
        'reason.max' => 'Keep it to 200 characters or fewer.',
        'reason.*' => 'Say why it\'s closed, so volunteers know.',
    ];

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
            'closure' => null,
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
        ], self::MESSAGES);

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
        // People who can't be emailed (not invited yet) are still on the shifts, so "nobody was on them" is only said when that's true.
        $onShifts = $shifts->contains(fn (Shift $shift) => $shift->assignments->isNotEmpty());
        $cancelled = $shifts->isEmpty() ? 'No shifts were on the calendar for those days.' : 'Cancelled '.$shifts->count().' '.Str::plural('shift', $shifts->count()).match (true) {
            $emailed === 1 => ' and emailed 1 person.',
            $emailed > 1 => ' and emailed '.$emailed.' people.',
            $onShifts => '.',
            default => ' and nobody was on them.',
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

    /** The same form for one closure, its area fixed, with "What this changes" built from the days in the query or the values just posted, and the remove step when ?remove=1. */
    public function edit(Request $request, AreaClosure $closure): View
    {
        Gate::forUser($request->user())->authorize('close', $closure->area);
        $old = $request->old();

        $values = [
            'area' => [$closure->area_id],
            'from' => $this->stringValue($old['from'] ?? $request->query('from', $closure->starts_on->toDateString())),
            'to' => $this->stringValue($old['to'] ?? $request->query('to', $closure->ends_on->toDateString())),
            'reason' => $this->stringValue($old['reason'] ?? $closure->reason),
        ];

        return view('calendar.closure', [
            'closure' => $closure,
            'areas' => collect([$closure->area]),
            'values' => $values,
            'chosen' => collect([$closure->area]),
            'preview' => null,
            'changes' => $this->changesPreview($request->user(), $closure, $values['from'], $values['to']),
            'removing' => $request->boolean('remove') ? $this->removePreview($closure) : null,
            'areaQuery' => $this->areaQuery($request),
            'backDate' => $closure->starts_on->toDateString(),
        ]);
    }

    public function update(Request $request, UpdateClosure $updateClosure, AreaClosure $closure): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('close', $closure->area);
        $areaQuery = $this->areaQuery($request);
        $values = [
            'from' => $this->stringValue($request->input('from')),
            'to' => $this->stringValue($request->input('to')),
        ];

        if ($request->filled('refresh')) {
            return redirect()->route('calendar.closures.edit', ['closure' => $closure] + array_filter($values) + $areaQuery)->withInput();
        }

        $request->validate([
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'reason' => ['required', 'string', 'max:200'],
        ], self::MESSAGES);

        try {
            $saved = $updateClosure->handle($request->user(), $closure, $values['from'], $values['to'], (string) $request->input('reason'));
        } catch (DomainException $e) {
            return redirect()->route('calendar.closures.edit', ['closure' => $closure] + $areaQuery)->withErrors(['closure' => $e->getMessage()])->withInput();
        }

        $outcome = $updateClosure->outcome;
        $status = 'Changed the '.$saved->area->name.' closure to '.$this->days($saved).'. '.$this->whatChanged($request->user(), $outcome['cancelled'], $outcome['restored'], $outcome['emailed'], $outcome['removed']);

        return redirect()->route('calendar.week', ['date' => $saved->starts_on->toDateString()] + $areaQuery)->with('status', $status);
    }

    public function remove(Request $request, RemoveClosure $removeClosure, AreaClosure $closure): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('close', $closure->area);
        $areaQuery = $this->areaQuery($request);
        $closure->loadMissing('area');

        try {
            $removeClosure->handle($request->user(), $closure);
        } catch (DomainException $e) {
            return redirect()->route('calendar.week', ['date' => $closure->starts_on->toDateString()] + $areaQuery)->withErrors(['closure' => $e->getMessage()]);
        }

        $outcome = $removeClosure->outcome;
        $what = $outcome['restored']->isEmpty() ? 'No shifts came back.' : $this->whatChanged($request->user(), collect(), $outcome['restored'], $outcome['emailed'], $outcome['removed']);
        $status = 'Removed the '.$closure->area->name.' closure for '.$this->days($closure).'. '.$what;

        return redirect()->route('calendar.week', ['date' => $closure->starts_on->toDateString()] + $areaQuery)->with('status', $status);
    }

    /** "Wednesday, November 25 to Friday, November 27", or the one day. */
    private function days(AreaClosure $closure): string
    {
        $first = $closure->starts_on->format('l, F j');

        return $closure->starts_on->equalTo($closure->ends_on) ? $first : $first.' to '.$closure->ends_on->format('l, F j');
    }

    /**
     * "Cancelled 1 shift, brought back 2 shifts and emailed 3 people.", and who was removed from a shift that came back.
     *
     * @param  Collection<int, Shift>  $cancelled
     * @param  Collection<int, Shift>  $restored
     * @param  Collection<int, User>  $removed
     */
    private function whatChanged(User $viewer, Collection $cancelled, Collection $restored, int $emailed, Collection $removed): string
    {
        $clauses = [];

        if ($cancelled->isNotEmpty()) {
            $clauses[] = 'cancelled '.$cancelled->count().' '.Str::plural('shift', $cancelled->count());
        }

        if ($restored->isNotEmpty()) {
            $clauses[] = 'brought back '.$restored->count().' '.Str::plural('shift', $restored->count());
        }

        if ($clauses === []) {
            return 'No shifts changed.';
        }

        if ($emailed > 0) {
            $clauses[] = 'emailed '.$emailed.' '.($emailed === 1 ? 'person' : 'people');
        }

        $sentence = ucfirst(collect($clauses)->join(', ', ' and ')).'.';

        if ($removed->isNotEmpty()) {
            $names = $removed->map(fn (User $person) => PersonSearch::displayName($viewer, $person))->join(', ', ' and ');
            $sentence .= ' '.$names.($removed->count() === 1 ? ' was' : ' were').' removed from a shift that came back, because they\'re now on another shift at that time.';
        }

        return $sentence;
    }

    /**
     * For the edit page: the shifts the new days would cancel and the ones they would bring back, with who is on each. Null until both days are real dates in order.
     *
     * @return array{cancels: list<array{when: string, people: string}>, brings: list<array{when: string, people: string}>, people: int, onShifts: bool}|null
     */
    private function changesPreview(User $viewer, AreaClosure $closure, string $from, string $to): ?array
    {
        if (! $this->isDate($from) || ! $this->isDate($to) || $to < $from) {
            return null;
        }

        $cancels = Shift::query()
            ->where('area_id', $closure->area_id)
            ->whereBetween('local_date', [$from, $to])
            ->whereNotBetween('local_date', [$closure->starts_on->toDateString(), $closure->ends_on->toDateString()])
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->with(['area', 'assignments.user'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $brings = $this->comingBack($closure)->reject(fn (Shift $shift) => $from <= $shift->local_date->toDateString() && $shift->local_date->toDateString() <= $to)->values();
        $both = $cancels->concat($brings);

        return [
            'cancels' => $this->rows($viewer, $cancels, false),
            'brings' => $this->rows($viewer, $brings, false),
            'people' => $this->activePeople($both)->count(),
            'onShifts' => $both->contains(fn (Shift $shift) => $shift->assignments->isNotEmpty()),
        ];
    }

    /**
     * For the remove step: how many shifts come back and how many people are emailed, and whether days have passed.
     *
     * @return array{text: string, started: bool}
     */
    private function removePreview(AreaClosure $closure): array
    {
        $back = $this->comingBack($closure);
        $count = $back->count();
        $people = $this->activePeople($back)->count();
        $days = $closure->starts_on->equalTo($closure->ends_on) ? $closure->starts_on->format('M j') : $closure->starts_on->format('M j').' to '.$closure->ends_on->format('M j');

        $what = match (true) {
            $count === 0 => 'No shifts come back.',
            default => ($count === 1 ? '1 shift comes back' : $count.' shifts come back').match (true) {
                $people === 0 => ' and nobody is emailed.',
                $people === 1 => ' and 1 person is emailed.',
                default => ' and '.$people.' people are emailed.',
            },
        };

        return [
            'text' => 'Remove the '.$closure->area->name.' closure for '.$days.'? '.$what,
            'started' => $closure->starts_on->toDateString() < Organization::current()->today()->toDateString(),
        ];
    }

    /**
     * The closure's cancelled shifts that haven't started and would come back if it stopped covering their days (see UpdateClosure::fate()).
     *
     * @return Collection<int, Shift>
     */
    private function comingBack(AreaClosure $closure): Collection
    {
        return Shift::query()
            ->where('area_closure_id', $closure->id)
            ->whereNotNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->with(['area', 'assignments.user'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (Shift $shift) => UpdateClosure::fate($shift, $closure) === null)
            ->values();
    }

    /**
     * One row per shift: when it is and who is on it, shown the way the viewer may see them.
     *
     * @param  Collection<int, Shift>  $shifts  With area and assignments.user loaded.
     * @return list<array{when: string, people: string}>
     */
    private function rows(User $viewer, Collection $shifts, bool $nameAreas): array
    {
        $timezone = Organization::current()->timezone;
        $nameOf = [];

        return $shifts->map(function (Shift $shift) use ($viewer, $nameAreas, $timezone, &$nameOf) {
            $start = $shift->starts_at->setTimezone($timezone);
            $when = $start->format('D M j').', '.TimeFormat::range($start, $shift->ends_at->setTimezone($timezone));

            // Several areas: each shift names its area, so they can be told apart without color.
            if ($nameAreas) {
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
        })->values()->all();
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

        $inRange = Shift::query()
            ->whereIn('area_id', $chosen->modelKeys())
            ->whereBetween('local_date', [$from, $to])
            ->where('starts_at', '>', now())
            ->with(['area', 'assignments.user'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();
        $shifts = $inRange->whereNull('cancelled_at')->values();
        $rows = $this->rows($viewer, $shifts, $chosen->count() > 1);

        $count = $shifts->count();
        $button = 'Close '.$this->names($chosen).($count === 0 ? '' : ' and cancel '.$count.' '.Str::plural('shift', $count));

        return [
            'rows' => $rows,
            'count' => $count,
            'people' => $this->activePeople($shifts)->count(),
            'onShifts' => $shifts->contains(fn (Shift $shift) => $shift->assignments->isNotEmpty()),
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
            ->filter(fn (User $user) => $user->canSignIn())
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
