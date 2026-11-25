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
use App\Rules\NoWebAddress;
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
            'people_needed' => (string) $request->old('people_needed', '1'),
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
            return redirect()->route('calendar.slots.create', array_filter($request->only(['area', 'title', 'date', 'start_time', 'end_time', 'repeats', 'people_needed', 'shadow_spots', 'ends', 'ends_on', 'fills_by']), fn ($value) => is_string($value) && $value !== '') + $areaQuery);
        }

        $area = $this->authorizedArea($request);
        $organization = Organization::current();
        $repeating = in_array($request->input('repeats'), ['weekly', 'every_other_week'], true);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:80', new NoWebAddress],
            'date' => ['bail', 'required', 'date_format:'.self::DATE_FORMAT, 'after_or_equal:'.$organization->today()->toDateString()],
            'start_time' => ['required', 'date_format:H:i'],
            'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
            'repeats' => ['required', Rule::in(['none', 'weekly', 'every_other_week'])],
            'people_needed' => ['required', 'integer', 'min:1', 'max:200'],
            'shadow_spots' => ['required', 'integer', 'min:0', 'max:200'],
            'ends' => [Rule::requiredIf($repeating), Rule::in(['never', 'on'])],
            'ends_on' => [Rule::requiredIf($repeating && $request->input('ends') === 'on'), 'nullable', 'date_format:'.self::DATE_FORMAT, 'after_or_equal:date'],
            'fills_by' => [Rule::requiredIf($repeating), Rule::in(['regulars', 'rotation'])],
        ], $this->messages());

        $actor = $request->user();
        $date = CarbonImmutable::parse($data['date'], 'UTC');

        if (! $repeating) {
            $shift = $createShift->handle($actor, $area, $data['date'], $data['start_time'], $data['end_time'], (int) $data['people_needed'], (int) $data['shadow_spots'], $data['title'] ?? null);

            return redirect()->route('calendar.week', ['date' => $data['date']] + $areaQuery)->with('status', 'Added '.($shift->title === null ? 'a shift for '.$area->name : 'the '.$this->lowerFirst($shift->title)).' on '.$date->format('l, F j').'.');
        }

        $slot = $createSlot->handle($actor, $area, [
            'weekday' => $date->dayOfWeek,
            'start_time' => $data['start_time'],
            'end_time' => $data['end_time'],
            'people_needed' => $data['people_needed'],
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
            'people_needed' => (string) $request->query('people_needed', '1'),
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
            'people_needed' => (string) $slot->people_needed,
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
        // "This shift and the ones after it": the form carries the date it starts from. Anything that isn't a date is ignored.
        $from = $this->fromDate($request->input('from'));
        $back = [$slot] + ($from === null ? [] : ['from' => $from->toDateString()]) + $areaQuery;

        if ($request->filled('refresh')) {
            return redirect()->route('calendar.slots.edit', $back)->withInput();
        }

        // A first date in the past is fine when it is unchanged; the action refuses a changed one before today.
        $data = $request->validate(SlotAttributes::formRules($slot), $this->messages());

        try {
            $result = $updateSlot->handle($request->user(), $slot, SlotAttributes::fromForm($data), $from);
        } catch (DomainException $e) {
            return redirect()->route('calendar.slots.edit', $back)->withErrors(['slot' => $e->getMessage()])->withInput();
        }

        return redirect()->route('calendar.slots.edit', $back)->with('slotSaved', [
            'updated' => $result->updatedShifts,
            'cancelled' => $result->cancelledShifts,
            'created' => $result->createdShifts,
            'emailed' => $result->emailed,
            // A list of pairs rather than an id-keyed map, so the order survives the session.
            'kept' => collect($result->keptReasons)->map(fn (string $reason, int $id) => ['id' => $id, 'reason' => $reason])->values()->all(),
        ]);
    }

    /** A `Y-m-d` date from the request as a local date, or null when it isn't one. */
    private function fromDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, Organization::current()->timezone);

        if ($date === false || $date->toDateString() !== $value) {
            return null;
        }

        // A date before today counts as today, the same as the action.
        $today = Organization::current()->today();

        return $date->lessThan($today) ? $today : $date;
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
            'existing' => $slot === null ? null : $this->existingShifts($slot, $this->fromDate($request->input('from'))),
            'saved' => $slot === null ? null : $this->savedNotice($request, $organization),
            'from' => $slot === null ? null : $this->fromDate($request->input('from')),
        ]);
    }

    /**
     * What saving does to the slot's shifts already on the calendar, as sentences. Null when it has none from today on.
     *
     * @return list<string>|null
     */
    private function existingShifts(WeeklySlot $slot, ?CarbonImmutable $from = null): ?array
    {
        $shifts = $slot->shifts()->whereNull('cancelled_at')->where('starts_at', '>', now())->withCount('assignments')->orderBy('local_date')->get();
        $shifts = $from === null ? $shifts : $shifts->filter(fn (Shift $shift) => $shift->local_date->toDateString() >= $from->toDateString())->values();
        $total = $shifts->count();

        if ($total === 0) {
            return null;
        }

        $edited = $shifts->filter(fn (Shift $shift) => $shift->edited_at !== null)->count();
        $staffed = $shifts->filter(fn (Shift $shift) => $shift->edited_at === null && $shift->assignments_count > 0)->count();
        $changes = $total - $edited;
        $last = $shifts->last()->local_date->format('l, F j');

        $sentences = [$total === 1 ? '1 shift from this slot is on the calendar, on '.$last.'.' : $total.' shifts from this slot are on the calendar, through '.$last.'.'];

        $including = $staffed === 0 ? '' : ', including the '.($staffed === 1 ? '1 with people on it' : $staffed.' with people on them');

        if ($edited === 0) {
            $sentences[] = $total === 1 ? 'Saving changes it.' : 'Saving changes all of them'.$including.'.';
        } else {
            $sentences[] = $changes === 0 ? 'Saving doesn\'t change any of them.' : 'Saving changes the '.$changes.' nobody changed on their own'.$including.'.';
            $sentences[] = $edited === 1 ? 'The 1 edited on its own keeps its own details.' : 'The '.$edited.' edited on their own keep their own details.';
        }

        if ($staffed > 0) {
            $sentences[] = 'Anyone on '.($staffed === 1 ? 'that shift' : 'those shifts').' is emailed if the time or what it\'s for changes.';
            $sentences[] = 'A shift with people on it keeps its old details if the change would double-book someone or leave too few shadow spots.';
        }

        return $sentences;
    }

    /**
     * After saving: what changed and who was emailed, then links to the shifts that kept their old details, each with why.
     *
     * @return array{summary: string, kept: list<array{shift: Shift, when: string, reason: string}>, offSchedule: bool}|null
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

        if (($saved['emailed'] ?? 0) > 0) {
            $summary[] = $plural($saved['emailed'], 'person was', 'people were').' emailed about their changed shifts.';
        }

        $reasons = collect($saved['kept'] ?? [])->pluck('reason', 'id');
        $kept = Shift::query()->whereKey($reasons->keys()->all())->orderBy('local_date')->get()
            ->map(fn (Shift $shift) => [
                'shift' => $shift,
                'when' => $shift->whenText($organization->timezone),
                // Each reason as a sentence, whether or not it ends in a period.
                'reason' => rtrim($reasons[$shift->id], '.').'.',
            ])->values()->all();

        if ($kept !== []) {
            $summary[] = $plural(count($kept), 'shift kept its old details:', 'shifts kept their old details:');
        }

        return [
            'summary' => implode(' ', $summary),
            'kept' => $kept,
            'offSchedule' => $reasons->contains(UpdateWeeklySlot::KEPT_OFF_SCHEDULE),
        ];
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

        // The first date the slot runs on is within two weeks of its first date, unless its last date comes first.
        $firstShift = $slot->datesWithin($startsOn, $startsOn->addDays(13))[0] ?? null;

        if ($firstShift !== null && $firstShift > $windowEnd->toDateString()) {
            $first = CarbonImmutable::parse($firstShift, 'UTC');
            // The window is today plus weeks_ahead * 7 - 1 days, so the first shift joins it that many days before.
            $opens = $first->subDays($organization->weeks_ahead * 7 - 1);

            return ['summary' => $summary, 'detail' => 'The first shift is on '.$first->format('l, F j').'. It appears on the calendar on '.$opens->format('l, F j').', since the calendar shows '.$organization->weeks_ahead.($organization->weeks_ahead === 1 ? ' week' : ' weeks').' ahead.'];
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

        // With no shifts on the calendar yet, nothing on the week leads back to the slot, so the status links to it.
        $links = $count > 0 ? [] : array_values(array_filter([
            ['label' => 'Edit the slot', 'url' => route('calendar.slots.edit', ['slot' => $slot] + $areaQuery)],
            $slot->is_rotation ? null : ['label' => 'Add a regular', 'url' => route('calendar.slots.regulars.create', ['slot' => $slot] + $areaQuery)],
        ]));

        return redirect()->route('calendar.week', ['date' => $weekOf] + $areaQuery)
            ->with('status', 'Added '.$slot->describe().', starting '.$date->format('l, F j').'. '.$onCalendar)
            ->with('statusLinks', $links);
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
            'people_needed.required' => 'At least 1 person is needed.',
            'people_needed.integer' => 'At least 1 person is needed.',
            'people_needed.min' => 'At least 1 person is needed.',
            'people_needed.max' => 'That is more people than one shift can have. Use 200 or fewer.',
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
