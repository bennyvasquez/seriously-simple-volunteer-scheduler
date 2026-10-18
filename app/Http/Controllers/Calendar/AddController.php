<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\SlotAttributes;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\Enums\SlotFrequency;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Adding to the calendar: the quick panel beside the week (screen 31) and the full weekly slot form (screen 35), which also edits an existing slot. Access to the calendar is checked by the route's can:viewAny middleware; each save is checked against the area it adds to.
 */
class AddController extends Controller
{
    use KeepsAreaFilter;

    private const DATE_FORMAT = 'Y-m-d';

    /** Screen 31: the week with the add panel beside it. */
    public function panel(Request $request, CalendarController $calendar): View
    {
        $areas = $this->manageableAreas($request);
        $organization = Organization::current();
        $date = $this->dateAsked($request, $organization);
        $areaId = (int) $request->old('area', $request->query('area'));

        $values = [
            'area' => $areas->contains('id', $areaId) ? $areaId : $areas->first()->id,
            'title' => (string) $request->old('title', ''),
            'date' => (string) $request->old('date', $date),
            'start_time' => (string) $request->old('start_time', '18:00'),
            'end_time' => (string) $request->old('end_time', '21:00'),
            'repeats' => (string) $request->old('repeats', 'none'),
            'headcount' => (string) $request->old('headcount', '1'),
            'shadow_spots' => (string) $request->old('shadow_spots', '0'),
            'ends' => (string) $request->old('ends', 'never'),
            'ends_on' => (string) $request->old('ends_on', ''),
            'fills_by' => (string) $request->old('fills_by', 'regulars'),
        ];

        $weekday = $this->weekdayName($values['date']);

        return view('calendar.add', $calendar->week($request)->getData() + [
            'manageable' => $areas,
            'values' => $values,
            'repeatOptions' => [
                'none' => "Doesn't repeat",
                'weekly' => 'Every week on '.$weekday,
                'every_other_week' => 'Every other week on '.$weekday,
            ],
        ]);
    }

    /** Creates a one-time shift, or a weekly slot when "Repeats" is chosen, and returns to the week with a note about what was added. */
    public function store(Request $request, CreateOneTimeShift $createShift, CreateWeeklySlot $createSlot): RedirectResponse
    {
        $areaQuery = $this->areaQuery($request);

        if ($request->filled('refresh')) {
            return redirect()->route('calendar.add', array_filter(['date' => $request->input('date'), 'area' => $request->input('area')]) + $areaQuery)->withInput();
        }

        if ($request->filled('more')) {
            // Sent as a POST so the CSRF token never lands in a URL; the typed values travel in the redirect's query.
            return redirect()->route('calendar.slots.create', array_filter($request->only(['area', 'title', 'date', 'start_time', 'end_time', 'repeats', 'headcount', 'shadow_spots', 'ends', 'ends_on', 'fills_by']), fn ($value) => is_string($value) && $value !== '') + $areaQuery);
        }

        $area = $this->authorizedArea($request);
        $organization = Organization::current();
        $repeating = in_array($request->input('repeats'), ['weekly', 'every_other_week'], true);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:80'],
            'date' => ['bail', 'required', 'date_format:'.self::DATE_FORMAT, 'after_or_equal:'.$organization->today()->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'repeats' => ['required', Rule::in(['none', 'weekly', 'every_other_week'])],
            'headcount' => ['required', 'integer', 'min:1', 'max:200'],
            'shadow_spots' => ['required', 'integer', 'min:0', 'max:200'],
            'ends' => [Rule::requiredIf($repeating), Rule::in(['never', 'on'])],
            'ends_on' => [Rule::requiredIf($repeating && $request->input('ends') === 'on'), 'nullable', 'date_format:'.self::DATE_FORMAT, 'after_or_equal:date'],
            'fills_by' => [Rule::requiredIf($repeating), Rule::in(['regulars', 'rotation'])],
        ], $this->messages());

        $actor = $request->user();
        $date = CarbonImmutable::parse($data['date'], 'UTC');

        if (! $repeating) {
            $shift = $createShift->handle($actor, $area, $data['date'], $data['start_time'], $data['end_time'], (int) $data['headcount'], (int) $data['shadow_spots'], $data['title'] ?? null);

            return redirect()->route('calendar.week', ['date' => $data['date']] + $areaQuery)->with('status', 'Added '.($shift->title === null ? 'a shift for '.$area->name : 'the '.$this->lowerFirst($shift->title)).' on '.$date->format('l, F j').'.');
        }

        $slot = $createSlot->handle($actor, $area, [
            'weekday' => $date->dayOfWeek,
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'headcount' => $data['headcount'],
            'shadow_spots' => $data['shadow_spots'],
            'frequency' => $data['repeats'],
            'valid_from' => $data['date'],
            'valid_until' => ($data['ends'] ?? 'never') === 'on' ? $data['ends_on'] : null,
            'title' => $data['title'] ?? null,
            'is_rotation' => ($data['fills_by'] ?? 'regulars') === 'rotation',
        ]);

        return $this->slotAdded($slot, $areaQuery);
    }

    /** Screen 35: the full form for a new weekly slot. The quick panel's "More options" sends what was typed along. */
    public function createSlot(Request $request): View
    {
        $areas = $this->manageableAreas($request);
        $organization = Organization::current();
        $date = $this->dateAsked($request, $organization);
        $areaId = (int) $request->query('area');
        $repeats = $request->query('repeats') === 'every_other_week' ? 'every_other_week' : 'weekly';

        $values = [
            'area' => $areas->contains('id', $areaId) ? $areaId : $areas->first()->id,
            'title' => (string) $request->query('title', ''),
            'weekday' => (string) CarbonImmutable::parse($date, 'UTC')->dayOfWeek,
            'start_time' => (string) $request->query('start_time', '18:00'),
            'end_time' => (string) $request->query('end_time', '21:00'),
            'headcount' => (string) $request->query('headcount', '1'),
            'shadow_spots' => (string) $request->query('shadow_spots', '0'),
            'frequency' => $repeats,
            'fills_by' => $request->query('fills_by') === 'rotation' ? 'rotation' : 'regulars',
            'valid_from' => $date,
            'valid_until' => $request->query('ends') === 'on' ? (string) $request->query('ends_on', '') : '',
        ];

        return $this->slotForm($request, $areas, $values, null);
    }

    /** The slot form for an existing slot, with what saving does to the shifts already on the calendar, and after a save, what it did. */
    public function editSlot(Request $request, WeeklySlot $slot): View
    {
        Gate::forUser($request->user())->authorize('manageSchedule', $slot->area);

        $areas = $this->manageableAreas($request);

        return $this->slotForm($request, $areas, [
            'area' => $slot->area_id,
            'title' => (string) $slot->title,
            'weekday' => (string) $slot->weekday,
            'start_time' => substr($slot->start_time, 0, 5),
            'end_time' => substr($slot->end_time, 0, 5),
            'headcount' => (string) $slot->headcount,
            'shadow_spots' => (string) $slot->shadow_spots,
            'frequency' => $slot->frequency->value,
            'fills_by' => $slot->is_rotation ? 'rotation' : 'regulars',
            'valid_from' => $slot->valid_from->toDateString(),
            'valid_until' => $slot->valid_until?->toDateString() ?? '',
        ], $slot);
    }

    /** Saves the slot form for an existing slot and returns to it with what changed. The area stays; the action checks this person can change its schedule. */
    public function updateSlot(Request $request, UpdateWeeklySlot $updateSlot, WeeklySlot $slot): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('manageSchedule', $slot->area);

        $areaQuery = $this->areaQuery($request);

        if ($request->filled('refresh')) {
            return redirect()->route('calendar.slots.edit', [$slot] + $areaQuery)->withInput();
        }

        // A first date in the past is fine when it is unchanged; the action refuses a changed one before today.
        $data = $request->validate(SlotAttributes::formRules($slot), $this->messages());

        try {
            $result = $updateSlot->handle($request->user(), $slot, SlotAttributes::fromForm($data));
        } catch (DomainException $e) {
            return redirect()->route('calendar.slots.edit', [$slot] + $areaQuery)->withErrors(['slot' => $e->getMessage()])->withInput();
        }

        // Split the kept shifts by why they matter: details that differ, or a date the slot no longer runs on.
        $groups = ['staffed' => [], 'staffedOff' => [], 'edited' => [], 'editedOff' => []];
        foreach ($result->keptShifts as $shift) {
            $off = ! $slot->occursOn($shift->local_date->toDateString());
            $groups[($shift->assignments_count > 0 ? 'staffed' : 'edited').($off ? 'Off' : '')][] = $shift->id;
        }

        return redirect()->route('calendar.slots.edit', [$slot] + $areaQuery)->with('slotSaved', [
            'updated' => $result->updatedShifts,
            'cancelled' => $result->cancelledShifts,
            'created' => $result->createdShifts,
        ] + $groups);
    }

    /** Creates a weekly slot from the full form. */
    public function storeSlot(Request $request, CreateWeeklySlot $createSlot): RedirectResponse
    {
        $areaQuery = $this->areaQuery($request);

        if ($request->filled('refresh')) {
            return redirect()->route('calendar.slots.create', $areaQuery)->withInput();
        }

        $area = $this->authorizedArea($request);
        $data = $request->validate(SlotAttributes::formRules(null), $this->messages());
        $slot = $createSlot->handle($request->user(), $area, SlotAttributes::fromForm($data));

        return $this->slotAdded($slot, $areaQuery);
    }

    private function slotForm(Request $request, Collection $areas, array $defaults, ?WeeklySlot $slot): View
    {
        $organization = Organization::current();
        $values = array_merge($defaults, array_intersect_key($request->old(), $defaults));
        $area = $areas->firstWhere('id', (int) $values['area']) ?? $areas->first();

        if ($slot !== null) {
            // The area of a slot doesn't change when editing.
            $values['area'] = $slot->area_id;
            $area = $slot->area;
        }

        return view('calendar.slot-form', [
            'organization' => $organization,
            'manageable' => $areas,
            'values' => $values,
            'slot' => $slot,
            'areaQuery' => $this->areaQuery($request),
            'preview' => $this->preview($organization, $area, $values),
            'weekdays' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
            'existing' => $slot === null ? null : $this->existingShifts($slot),
            'saved' => $slot === null ? null : $this->savedNotice($request, $organization),
        ]);
    }

    /**
     * What saving does to the slot's shifts already on the calendar, as sentences. Null when it has none from today on.
     *
     * @return list<string>|null
     */
    private function existingShifts(WeeklySlot $slot): ?array
    {
        $shifts = $slot->shifts()->whereNull('cancelled_at')->where('starts_at', '>', now())->withCount('assignments')->orderBy('local_date')->get();
        $total = $shifts->count();

        if ($total === 0) {
            return null;
        }

        $staffed = $shifts->filter(fn (Shift $shift) => $shift->assignments_count > 0)->count();
        $edited = $shifts->filter(fn (Shift $shift) => $shift->assignments_count === 0 && $shift->edited_at !== null)->count();
        $changes = $total - $staffed - $edited;
        $last = $shifts->last()->local_date->format('l, F j');

        $sentences = [$total === 1 ? '1 shift from this slot is on the calendar, on '.$last.'.' : $total.' shifts from this slot are on the calendar, through '.$last.'.'];

        if ($staffed === 0 && $edited === 0) {
            $sentences[] = $total === 1 ? 'Saving changes it, since nobody is on it yet.' : 'Saving changes all of them, since nobody is on them yet.';
        } else {
            $sentences[] = $changes === 0 ? 'Saving doesn\'t change any of them.' : 'Saving changes the '.$changes.' that nobody is on yet.';

            if ($staffed > 0) {
                $sentences[] = $staffed === 1 ? 'The 1 with people on it keeps its old details.' : 'The '.$staffed.' with people on them keep their old details.';
            }

            if ($edited > 0) {
                $sentences[] = $edited === 1 ? 'The 1 edited on its own keeps its own details.' : 'The '.$edited.' edited on their own keep their own details.';
            }
        }

        return $sentences;
    }

    /**
     * After saving: what changed, then links to the shifts that kept their old details.
     *
     * @return array{summary: string, groups: list<array{heading: string, shifts: Collection<int, Shift>}>}|null
     */
    private function savedNotice(Request $request, Organization $organization): ?array
    {
        $saved = $request->session()->get('slotSaved');

        if (! is_array($saved)) {
            return null;
        }

        $plural = fn (int $count, string $one, string $many) => $count === 1 ? '1 '.$one : $count.' '.$many;
        $summary = [$saved['updated'] > 0 ? 'Updated '.$plural($saved['updated'], 'shift.', 'shifts.') : 'Saved the slot.'];

        if ($saved['cancelled'] > 0) {
            $summary[] = 'Cancelled '.$plural($saved['cancelled'], 'date', 'dates').' that no longer fit the slot. Nobody was on '.($saved['cancelled'] === 1 ? 'it.' : 'them.');
        }

        if ($saved['created'] > 0) {
            $summary[] = 'Added '.$plural($saved['created'], 'new shift.', 'new shifts.');
        }

        $headings = [
            'staffed' => fn (int $count) => $plural($count, 'shift with people on it kept its old details:', 'shifts with people on them kept their old details:'),
            'staffedOff' => fn (int $count) => $plural($count, 'shift with people on it is no longer on this slot\'s schedule. Cancel it or move the people:', 'shifts with people on them are no longer on this slot\'s schedule. Cancel them or move the people:'),
            'edited' => fn (int $count) => $plural($count, 'shift edited on its own kept its own details:', 'shifts edited on their own kept their own details:'),
            'editedOff' => fn (int $count) => $plural($count, 'shift edited on its own is no longer on this slot\'s schedule. Cancel it if it isn\'t needed:', 'shifts edited on their own are no longer on this slot\'s schedule. Cancel them if they aren\'t needed:'),
        ];

        $groups = [];
        foreach ($headings as $kind => $heading) {
            $ids = $saved[$kind] ?? [];

            if ($ids === []) {
                continue;
            }

            $groups[] = [
                'heading' => $heading(count($ids)),
                'shifts' => Shift::query()->whereKey($ids)->orderBy('local_date')->get()->map(fn (Shift $shift) => ['shift' => $shift, 'when' => $shift->whenText($organization->timezone)]),
            ];
        }

        return ['summary' => implode(' ', $summary), 'groups' => $groups];
    }

    /**
     * What the slot would make: a one-line description, then the first three dates and any closures that skip a date. Null when the values can't describe a slot yet.
     *
     * @return array{summary: string, detail: string}|null
     */
    private function preview(Organization $organization, Area $area, array $values): ?array
    {
        $validFrom = $values['valid_from'];

        if (! $this->isDate($validFrom) || ! in_array((string) $values['weekday'], ['0', '1', '2', '3', '4', '5', '6'], true)
            || ! preg_match('/^\d{2}:\d{2}$/', $values['start_time']) || ! preg_match('/^\d{2}:\d{2}$/', $values['end_time'])
            || $values['start_time'] === $values['end_time']) {
            return null;
        }

        $frequency = SlotFrequency::tryFrom($values['frequency']) ?? SlotFrequency::Weekly;
        $weekday = (int) $values['weekday'];
        $validUntil = $this->isDate($values['valid_until']) ? $values['valid_until'] : null;

        $slot = new WeeklySlot([
            'weekday' => $weekday,
            'start_time' => $values['start_time'],
            'end_time' => $values['end_time'],
            'frequency' => $frequency,
            'anchor_date' => $frequency === SlotFrequency::EveryOtherWeek ? $validFrom : null,
            'valid_from' => $validFrom,
            'valid_until' => $validUntil,
        ]);

        // The same window the nightly run and the save use: today for weeks_ahead * 7 days.
        $windowStart = CarbonImmutable::parse($organization->today()->toDateString(), 'UTC');
        $windowEnd = $windowStart->addDays($organization->weeks_ahead * 7 - 1);
        $closures = AreaClosure::query()->where('area_id', $area->id)
            ->where('starts_on', '<=', $windowEnd->toDateString())->where('ends_on', '>=', $windowStart->toDateString())->get();

        $dates = [];
        $skipped = [];
        foreach ($slot->datesWithin($windowStart, $windowEnd) as $date) {
            $day = CarbonImmutable::parse($date, 'UTC');

            if ($closures->contains(fn (AreaClosure $closure) => $closure->covers($date))) {
                $skipped[] = $day->format('D M j');

                continue;
            }

            $dates[] = $day->format('M j');
        }

        $startsOn = CarbonImmutable::parse($validFrom, 'UTC');
        $summary = $slot->describe().($frequency === SlotFrequency::Weekly ? ', every week' : '').', starting '.$startsOn->format('D M j').'.';

        if ($startsOn->greaterThan($windowEnd)) {
            $opens = $startsOn->subWeeks($organization->weeks_ahead);

            return ['summary' => $summary, 'detail' => 'The first shifts appear on the calendar on '.$opens->format('l, F j').', '.$organization->weeks_ahead.' weeks before '.$startsOn->format('l, F j').'.'];
        }

        $detail = 'Shifts appear on the calendar as soon as you save, '.$organization->weeks_ahead.' weeks ahead';
        $listed = array_slice($dates, 0, 3);
        $detail .= $listed === [] ? ', but none of the dates in that time fall on this slot.' : ': '.implode(', ', $listed).(count($dates) > 3 ? ' and so on.' : '.');
        $skippedNames = collect($skipped);
        if ($skippedNames->isNotEmpty()) {
            $detail .= ' '.$skippedNames->join(', ', ' and ').($skippedNames->count() === 1 ? ' is' : ' are').' skipped because '.$area->name.' is closed '.($skippedNames->count() === 1 ? 'that day.' : 'those days.');
        }

        return ['summary' => $summary, 'detail' => $detail];
    }

    private function slotAdded(WeeklySlot $slot, array $areaQuery): RedirectResponse
    {
        $organization = Organization::current();
        $date = CarbonImmutable::parse($slot->valid_from->toDateString(), 'UTC');
        $count = $slot->shifts()->count();
        $onCalendar = match ($count) {
            0 => 'No shifts are on the calendar yet.',
            1 => '1 shift is on the calendar.',
            default => $count.' shifts are on the calendar.',
        };
        $weekOf = max($organization->today()->toDateString(), $date->toDateString());

        return redirect()->route('calendar.week', ['date' => $weekOf] + $areaQuery)
            ->with('status', 'Added '.$slot->describe().', starting '.$date->format('l, F j').'. '.$onCalendar);
    }

    /** @return Collection<int, Area> */
    private function manageableAreas(Request $request): Collection
    {
        $user = $request->user();
        $areas = Area::query()->orderBy('id')->get()->filter(fn (Area $area) => Gate::forUser($user)->allows('manageSchedule', $area))->values();

        abort_if($areas->isEmpty(), 403);

        return $areas;
    }

    /** The area posted, after checking this person can change its schedule. */
    private function authorizedArea(Request $request): Area
    {
        $areaId = $request->input('area');
        $area = is_scalar($areaId) && ctype_digit((string) $areaId) ? Area::query()->find((int) $areaId) : null;

        if ($area === null) {
            throw ValidationException::withMessages(['area' => 'Pick an area.']);
        }

        Gate::forUser($request->user())->authorize('manageSchedule', $area);

        return $area;
    }

    private function dateAsked(Request $request, Organization $organization): string
    {
        $value = $request->query('date');

        return $this->isDate($value) ? $value : $organization->today()->toDateString();
    }

    private function isDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
            return false;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');

        return $date !== false && $date->toDateString() === $value;
    }

    private function weekdayName(string $date): string
    {
        return $this->isDate($date) ? CarbonImmutable::parse($date, 'UTC')->format('l') : 'that day';
    }

    private function lowerFirst(string $title): string
    {
        return preg_match('/^\p{Lu}\p{Ll}/u', $title) === 1 ? Str::lcfirst($title) : $title;
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'date.required' => 'Pick a date.',
            'date.date_format' => 'Pick a date.',
            'date.after_or_equal' => 'Pick a date from today on.',
            'valid_from.required' => 'Pick a first date.',
            'valid_from.date_format' => 'Pick a first date.',
            'valid_from.after_or_equal' => 'Pick a date from today on.',
            'valid_until.after_or_equal' => 'The last date has to be on or after the first date.',
            'valid_until.date_format' => 'Pick a last date, or leave it empty.',
            'ends_on.required' => 'Pick the last date, or choose "No end date yet".',
            'ends_on.after_or_equal' => 'The last date has to be on or after the first date.',
            'ends_on.date_format' => 'Pick the last date, or choose "No end date yet".',
            'start_time.required' => 'Pick a start time.',
            'start_time.date_format' => 'Pick a start time.',
            'end_time.required' => 'Pick an end time.',
            'end_time.date_format' => 'Pick an end time.',
            'end_time.different' => 'A shift has to end at a different time than it starts.',
            'headcount.required' => 'At least 1 person is needed.',
            'headcount.integer' => 'At least 1 person is needed.',
            'headcount.min' => 'At least 1 person is needed.',
            'headcount.max' => 'That is more people than one shift can have. Use 200 or fewer.',
            'shadow_spots.required' => 'Enter 0 if there are no shadow spots.',
            'shadow_spots.integer' => 'Enter 0 if there are no shadow spots.',
            'shadow_spots.min' => 'Enter 0 if there are no shadow spots.',
            'shadow_spots.max' => 'That is more shadow spots than one shift can have. Use 200 or fewer.',
            'title.max' => 'Keep it to 80 characters or fewer.',
            'weekday.between' => 'Pick a day of the week.',
            'weekday.required' => 'Pick a day of the week.',
        ];
    }
}
