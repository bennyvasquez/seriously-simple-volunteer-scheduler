<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\EndRegular;
use App\Actions\Scheduling\NeedsOverConfirmation;
use App\Calendar\PersonSearch;
use App\Enums\SlotFrequency;
use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use InvalidArgumentException;

/**
 * Adding a regular to a weekly slot (screen 36), beside the week. Access to the calendar is checked by the route's can:viewAny middleware; each request is checked against the slot's area. Choosing the person works without JavaScript through ?q= and ?person=.
 */
class RegularController extends Controller
{
    use KeepsAreaFilter;

    public function create(Request $request, CalendarController $calendar, WeeklySlot $slot): View
    {
        Gate::forUser($request->user())->authorize('manageSchedule', $slot->area);
        abort_if($slot->is_rotation, 404);

        $organization = Organization::current();
        $today = $organization->today();
        $viewer = $request->user();
        $byEmail = PersonSearch::byEmail($viewer);
        $current = $this->currentRegulars($slot, $today);
        $person = $this->person($request, $viewer);
        $search = PersonSearch::find($viewer, (string) $request->query('q', ''), $byEmail, $current->pluck('user_id'));
        $firstDate = $this->nextSlotDate($slot, $today);
        $values = [
            'frequency' => (string) $request->old('frequency', $request->query('frequency', 'weekly')),
            'weeks' => (string) $request->old('weeks', $request->query('weeks', '')),
            'starts_on' => (string) $request->old('starts_on', $request->query('starts_on', $firstDate)),
            'ends_on' => (string) $request->old('ends_on', $request->query('ends_on', '')),
        ];
        $overDates = $request->boolean('over') && $person !== null ? $this->overDates($slot, $person, $values) : [];

        return view('calendar.add-regular', $calendar->week($request)->getData() + [
            'slot' => $slot->load('area'),
            'slotText' => $slot->describe(),
            'needs' => $this->needs($slot, $current),
            'person' => $person,
            'search' => $search,
            'byEmail' => $byEmail,
            'canChooseFrequency' => $slot->frequency === SlotFrequency::Weekly,
            'weekOptions' => $slot->frequency === SlotFrequency::Weekly ? $this->weekOptions($current, $today) : [],
            'values' => $values,
            'overDates' => $overDates,
        ]);
    }

    public function store(Request $request, AddRegular $addRegular, WeeklySlot $slot): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('manageSchedule', $slot->area);

        if ($slot->is_rotation) {
            throw ValidationException::withMessages(['slot' => 'A rotation has no regulars. Volunteers sign up for it one date at a time.']);
        }

        $areaQuery = $this->areaQuery($request);
        $back = fn (?int $person = null) => redirect()->route('calendar.slots.regulars.create', array_filter(['slot' => $slot, 'person' => $person, 'date' => $request->input('date')]) + $areaQuery)->withInput();

        $validator = Validator::make($request->all(), [
            'user' => ['required', 'integer', 'exists:users,id'],
            'frequency' => ['nullable', 'in:weekly,every_other_week'],
            'weeks' => ['nullable', 'string', 'max:10'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'over_confirmed' => ['nullable', 'boolean'],
        ], [
            'user.*' => 'Pick a person.',
            'frequency.in' => 'Pick every week or every other week.',
            'starts_on.*' => 'Pick the first date.',
            'ends_on.date_format' => 'Pick the last date, or leave it empty.',
            'ends_on.after_or_equal' => 'The last date has to be on or after the first date.',
        ]);

        $person = is_numeric($request->input('user')) ? (int) $request->input('user') : null;

        if ($validator->fails()) {
            return $back($person)->withErrors($validator);
        }

        $data = $validator->validated();
        // A slot that already runs every other week has regulars who work every date it runs.
        $frequency = $slot->frequency === SlotFrequency::Weekly ? SlotFrequency::from($data['frequency'] ?? 'weekly') : SlotFrequency::Weekly;
        $anchor = null;

        if ($frequency === SlotFrequency::EveryOtherWeek) {
            $weeks = (string) ($data['weeks'] ?? '');
            $anchor = $weeks === 'pick' ? $data['starts_on'] : $weeks;
            $weekday = $slot->weekdayName();

            if ($weeks === '' || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $anchor)) {
                return $back($person)->withErrors(['weeks' => 'Pick which weeks.']);
            }

            if (CarbonImmutable::parse($anchor, 'UTC')->dayOfWeek !== $slot->weekday) {
                return $back($person)->withErrors([$weeks === 'pick' ? 'starts_on' : 'weeks' => 'Pick a '.$weekday.'.']);
            }
        }

        $regularUser = User::query()->findOrFail($data['user']);

        try {
            $regular = $addRegular->handle($request->user(), $slot, $regularUser, $data['starts_on'], $data['ends_on'] ?? null, $frequency, $anchor, $request->boolean('over_confirmed'));
        } catch (NeedsOverConfirmation) {
            // Back to the same page with what was picked and the flag that shows the confirm step; the dates are worked out again there.
            $typed = array_filter(['frequency' => $data['frequency'] ?? null, 'weeks' => $data['weeks'] ?? null, 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?? null], fn ($value) => $value !== null && $value !== '');

            return redirect()->route('calendar.slots.regulars.create', array_filter(['slot' => $slot, 'person' => $regularUser->id, 'date' => $request->input('date')]) + $typed + ['over' => 1] + $areaQuery);
        } catch (DomainException|InvalidArgumentException $e) {
            return $back($person)->withErrors(['slot' => $e->getMessage()]);
        }

        $added = $regular->added_to_shifts;
        $starts = $regular->firstWorkedDate() ?? CarbonImmutable::parse($data['starts_on'], 'UTC');

        $first = $regularUser->shortName();
        $how = $frequency === SlotFrequency::EveryOtherWeek || $slot->frequency === SlotFrequency::EveryOtherWeek ? 'every other week' : 'every week';

        return redirect()->route('calendar.week', ['date' => $starts->toDateString()] + $areaQuery)
            ->with('status', $first.' is now a regular, '.$how.' from '.$starts->format('M j').'. '.match ($added) {
                0 => 'No shifts are on the schedule for them yet.',
                1 => 'We added them to 1 shift already on the schedule.',
                default => 'We added them to '.$added.' shifts already on the schedule.',
            }.$this->overlapsSentence($regular->skipped_overlaps).($regularUser->canSignIn() ? ' We\'ll email '.$first.' to let them know.' : ''));
    }

    /** Ending a regular's time on the slot, beside the week, opened from a shift (`?date=`) the regular is on. */
    public function end(Request $request, CalendarController $calendar, WeeklySlot $slot, SlotRegular $regular): View
    {
        $this->checkEnd($request, $slot, $regular);
        $date = $this->dateParam($request);
        $lastDate = (string) $request->old('last_date', $request->query('last_date', CarbonImmutable::parse($date, 'UTC')->subDay()->toDateString()));

        return $this->endView($request, $calendar, $slot, $regular, $date, $lastDate);
    }

    /** Ends the regular's time. A last date that has already passed with past shifts after it comes back to the same page, listing them, until the coordinator confirms. */
    public function endStore(Request $request, CalendarController $calendar, EndRegular $endRegular, WeeklySlot $slot, SlotRegular $regular): View|RedirectResponse
    {
        $this->checkEnd($request, $slot, $regular);
        $viewer = $request->user();
        $date = $this->dateParam($request);
        $areaQuery = $this->areaQuery($request);
        $back = fn (array $extra = []) => redirect()->route('calendar.slots.regulars.end', [$slot, $regular, 'date' => $date] + $extra + $areaQuery)->withInput();

        $validator = Validator::make($request->all(), ['last_date' => ['required', 'date_format:Y-m-d']], ['last_date.*' => 'Pick the last date they work.']);

        if ($validator->fails()) {
            return $back()->withErrors($validator);
        }

        $lastDate = (string) $validator->validated()['last_date'];
        $past = $endRegular->pastShifts($regular, $lastDate);

        if ($past->isNotEmpty() && ! $request->boolean('past_confirmed')) {
            return $this->endView($request, $calendar, $slot, $regular, $date, $lastDate, $past);
        }

        try {
            $ended = $endRegular->handle($viewer, $regular, $lastDate, $request->boolean('past_confirmed'));
        } catch (DomainException $e) {
            return $back()->withErrors(['last_date' => $e->getMessage()]);
        }

        $first = $regular->user->shortName();
        $count = $ended->removed_from_shifts;
        $shift = $slot->shifts()->where('local_date', $date)->first();
        // A last date before the regular's start means they never worked a date, so the date isn't named. A sentence about shifts only when they were removed from some.
        $status = $first.' is no longer a regular'.($ended->before_start ? '.' : ' after '.CarbonImmutable::parse($lastDate, 'UTC')->format('l, F j').'.')
            .($count === 0 ? '' : ' They were removed from '.$count.($count === 1 ? ' shift.' : ' shifts.'));

        return ($shift !== null
            ? redirect()->route('calendar.shift', [$shift] + $areaQuery)
            : redirect()->route('calendar.week', ['date' => $date] + $areaQuery))->with('status', $status);
    }

    private function checkEnd(Request $request, WeeklySlot $slot, SlotRegular $regular): void
    {
        abort_unless($regular->weekly_slot_id === $slot->id, 404);
        Gate::forUser($request->user())->authorize('manageSchedule', $slot->area);
    }

    /** The date of the shift the page was opened from, today when it's missing or isn't a date. */
    private function dateParam(Request $request): string
    {
        $date = (string) $request->input('date', $request->query('date', ''));

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1 ? $date : Organization::current()->today()->toDateString();
    }

    /** @param  Collection<int, Shift>  $past */
    private function endView(Request $request, CalendarController $calendar, WeeklySlot $slot, SlotRegular $regular, string $date, string $lastDate, ?Collection $past = null): View
    {
        $request->query->set('date', $date);
        $viewer = $request->user();
        $regular->loadMissing('user');

        return view('calendar.end-regular', $calendar->week($request)->getData() + [
            'slot' => $slot->load('area'),
            'slotText' => $slot->describe(),
            'regular' => $regular,
            'person' => $regular->user,
            'personName' => PersonSearch::displayName($viewer, $regular->user),
            'lastDate' => $lastDate,
            'pastShifts' => $past,
        ]);
    }

    /**
     * The dates the picked person would put over the people needed, for the confirm step, from the values in the page's address. Empty when the values don't make a valid regular.
     *
     * @param  array{frequency: string, weeks: string, starts_on: string, ends_on: string}  $values
     * @return list<array{date: string, people: int, needed: int}>
     */
    private function overDates(WeeklySlot $slot, User $person, array $values): array
    {
        $dateFormat = fn (string $value) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;

        if (! $dateFormat($values['starts_on']) || ($values['ends_on'] !== '' && (! $dateFormat($values['ends_on']) || $values['ends_on'] < $values['starts_on']))) {
            return [];
        }

        $everyOther = $slot->frequency === SlotFrequency::Weekly && $values['frequency'] === 'every_other_week';
        $anchor = $values['weeks'] === 'pick' ? $values['starts_on'] : $values['weeks'];

        if ($everyOther && (! $dateFormat($anchor) || CarbonImmutable::parse($anchor, 'UTC')->dayOfWeek !== $slot->weekday)) {
            return [];
        }

        $regular = new SlotRegular([
            'user_id' => $person->id,
            'frequency' => $everyOther ? SlotFrequency::EveryOtherWeek : SlotFrequency::Weekly,
            'anchor_date' => $everyOther ? $anchor : null,
            'starts_on' => $values['starts_on'],
            'ends_on' => $values['ends_on'] === '' ? null : $values['ends_on'],
        ]);
        $regular->weeklySlot()->associate($slot);

        return app(AddRegular::class)->overDates($slot, $regular);
    }

    /**
     * " Not added on Oct 29, which overlaps another shift they're on." Empty when no date was left out.
     *
     * @param  list<string>  $dates
     */
    private function overlapsSentence(array $dates): string
    {
        if ($dates === []) {
            return '';
        }

        $days = collect($dates)->map(fn (string $date) => CarbonImmutable::parse($date, 'UTC')->format('M j'))->join(', ', ' and ');

        return ' Not added on '.$days.(count($dates) === 1 ? ', which overlaps another shift they\'re on.' : ', which overlap other shifts they\'re on.');
    }

    /** @return Collection<int, SlotRegular> The slot's regulars who haven't ended, with their people loaded. */
    private function currentRegulars(WeeklySlot $slot, CarbonImmutable $today): Collection
    {
        return $slot->regulars()->with('user')
            ->currentOrFuture($today->toDateString())
            ->orderBy('id')->get();
    }

    /** "Needs 2 people every week. Now: Jordan every week, and Carol every other week from Oct 9." */
    private function needs(WeeklySlot $slot, Collection $current): string
    {
        $needs = 'Needs '.$slot->people_needed.($slot->people_needed === 1 ? ' person' : ' people').($slot->frequency === SlotFrequency::EveryOtherWeek ? ' every other week.' : ' every week.');

        if ($current->isEmpty()) {
            return $needs.' No regulars yet.';
        }

        $now = $current->map(function (SlotRegular $regular) use ($slot) {
            $name = $regular->user->shortName();

            if ($regular->frequency === SlotFrequency::EveryOtherWeek) {
                return $name.' every other week from '.$regular->anchor_date->format('M j');
            }

            return $name.($slot->frequency === SlotFrequency::EveryOtherWeek ? ' every other week' : ' every week');
        })->all();

        $last = array_pop($now);

        return $needs.' Now: '.($now === [] ? $last : implode(', ', $now).', and '.$last).'.';
    }

    /**
     * The choices under "Which weeks": the weeks the slot's alternating regulars are off and the weeks they work. The value is the earliest date in that pattern that is today or later, which becomes the anchor date. Regulars with the same pattern share one choice ("The weeks Carol and Dana are off").
     *
     * @return list<array{value: string, label: string, detail: string}>
     */
    private function weekOptions(Collection $current, CarbonImmutable $today): array
    {
        $todayDate = $today->toDateString();
        $groups = [];

        foreach ($current->where('frequency', SlotFrequency::EveryOtherWeek) as $regular) {
            foreach (['off' => 7, 'same' => 0] as $kind => $shift) {
                $first = CarbonImmutable::parse($regular->anchor_date->toDateString(), 'UTC')->addDays($shift);

                if ($first->toDateString() < $todayDate) {
                    $first = $first->addDays(14 * (int) ceil($first->diffInDays(CarbonImmutable::parse($todayDate, 'UTC')) / 14));
                }

                while ($first->subDays(14)->toDateString() >= $todayDate) {
                    $first = $first->subDays(14);
                }

                $groups[$kind.'|'.$first->toDateString()][] = $regular->user->shortName();
            }
        }

        $options = [];

        foreach ($groups as $key => $names) {
            [$kind, $date] = explode('|', $key);
            $first = CarbonImmutable::parse($date, 'UTC');
            $who = count($names) === 1 ? $names[0] : implode(', ', array_slice($names, 0, -1)).' and '.end($names);

            $options[] = [
                'value' => $date,
                'label' => $kind === 'off' ? 'The weeks '.$who.(count($names) === 1 ? ' is' : ' are').' off' : 'The same weeks as '.$who,
                'detail' => 'Starting '.$first->format('D M j').', then '.$first->addDays(14)->format('M j').', '.$first->addDays(28)->format('M j'),
            ];
        }

        return $options;
    }

    /** The first date from today that the slot runs, for the "First date" box. */
    private function nextSlotDate(WeeklySlot $slot, CarbonImmutable $today): string
    {
        $dates = $slot->datesWithin($today, $today->addDays(28));

        return $dates[0] ?? $today->toDateString();
    }

    /** The person picked from the search, if they're active. Shown by short name when the viewer may not see their full name (spec section 13). */
    private function person(Request $request, User $viewer): ?User
    {
        $id = $request->old('user', $request->query('person'));
        $person = is_numeric($id) ? User::query()->whereNull('deactivated_at')->find((int) $id) : null;
        $person?->setAttribute('display_name', PersonSearch::displayName($viewer, $person));

        return $person;
    }
}
