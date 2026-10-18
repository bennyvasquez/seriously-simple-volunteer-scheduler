<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\AddRegular;
use App\Enums\SlotFrequency;
use App\Http\Controllers\Controller;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
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

    private const MIN_SEARCH = 2;

    public function create(Request $request, CalendarController $calendar, WeeklySlot $slot): View
    {
        Gate::forUser($request->user())->authorize('manageSchedule', $slot->area);
        abort_if($slot->is_rotation, 404);

        $organization = Organization::current();
        $today = $organization->today();
        $viewer = $request->user();
        $byEmail = Area::query()->get()->every(fn (Area $area) => $viewer->canManageArea($area));
        $current = $this->currentRegulars($slot, $today);
        $person = $this->person($request, $viewer);
        $search = $this->search($request, $viewer, $current, $byEmail);
        $firstDate = $this->nextSlotDate($slot, $today);

        return view('calendar.add-regular', $calendar->week($request)->getData() + [
            'slot' => $slot->load('area'),
            'slotText' => $slot->describe(),
            'needs' => $this->needs($slot, $current, $viewer),
            'person' => $person,
            'search' => $search,
            'byEmail' => $byEmail,
            'canChooseFrequency' => $slot->frequency === SlotFrequency::Weekly,
            'weekOptions' => $slot->frequency === SlotFrequency::Weekly ? $this->weekOptions($current, $today) : [],
            'values' => [
                'frequency' => (string) $request->old('frequency', 'weekly'),
                'weeks' => (string) $request->old('weeks', ''),
                'starts_on' => (string) $request->old('starts_on', $firstDate),
                'ends_on' => (string) $request->old('ends_on', ''),
            ],
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
            $regular = $addRegular->handle($request->user(), $slot, $regularUser, $data['starts_on'], $data['ends_on'] ?? null, $frequency, $anchor);
        } catch (DomainException|InvalidArgumentException $e) {
            return $back($person)->withErrors(['slot' => $e->getMessage()]);
        }

        $entry = ChangeLogEntry::query()->where('subject_type', $regular->getMorphClass())->where('subject_id', $regular->id)->where('action', 'regular.added')->latest('id')->first();
        $added = count($entry?->details['added_to_shift_ids'] ?? []);

        $first = $regularUser->firstName();
        $how = $frequency === SlotFrequency::EveryOtherWeek || $slot->frequency === SlotFrequency::EveryOtherWeek ? 'every other week' : 'every week';

        return redirect()->route('calendar.week', ['date' => $data['starts_on']] + $areaQuery)
            ->with('status', $first.' is now a regular, '.$how.' from '.CarbonImmutable::parse($data['starts_on'])->format('M j').'. '.match ($added) {
                0 => 'No shifts are on the schedule for them yet.',
                1 => 'We added them to 1 shift already on the schedule.',
                default => 'We added them to '.$added.' shifts already on the schedule.',
            }.' We\'ll email '.$first.' to let them know.');
    }

    /** @return Collection<int, SlotRegular> The slot's regulars who haven't ended, with their people loaded. */
    private function currentRegulars(WeeklySlot $slot, CarbonImmutable $today): Collection
    {
        return $slot->regulars()->with('user')
            ->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today->toDateString()))
            ->orderBy('id')->get();
    }

    /** "Needs 2 people every week. Now: Jordan every week, and Carol every other week from Oct 9." */
    private function needs(WeeklySlot $slot, Collection $current, User $viewer): string
    {
        $needs = 'Needs '.$slot->headcount.($slot->headcount === 1 ? ' person' : ' people').($slot->frequency === SlotFrequency::EveryOtherWeek ? ' every other week.' : ' every week.');

        if ($current->isEmpty()) {
            return $needs.' No regulars yet.';
        }

        $now = $current->map(function (SlotRegular $regular) use ($slot, $viewer) {
            $name = $viewer->canSee($regular->user) ? $regular->user->firstName() : $regular->user->shortName();

            if ($regular->frequency === SlotFrequency::EveryOtherWeek) {
                return $name.' every other week from '.$regular->anchor_date->format('M j');
            }

            return $name.($slot->frequency === SlotFrequency::EveryOtherWeek ? ' every other week' : ' every week');
        })->all();

        $last = array_pop($now);

        return $needs.' Now: '.($now === [] ? $last : implode(', ', $now).', and '.$last).'.';
    }

    /**
     * The choices under "Which weeks": for each regular who works every other week, the weeks they are off and the weeks they work. The value is the first date of that pattern from today, which becomes the anchor date.
     *
     * @return list<array{value: string, label: string, detail: string}>
     */
    private function weekOptions(Collection $current, CarbonImmutable $today): array
    {
        $options = [];

        foreach ($current->where('frequency', SlotFrequency::EveryOtherWeek) as $regular) {
            $name = $regular->user->firstName();

            foreach ([['The weeks '.$name.' is off', 7], ['The same weeks as '.$name, 0]] as [$label, $shift]) {
                $first = CarbonImmutable::parse($regular->anchor_date->toDateString(), 'UTC')->addDays($shift);

                while ($first->lessThan($today)) {
                    $first = $first->addDays(14);
                }

                $options[] = [
                    'value' => $first->toDateString(),
                    'label' => $label,
                    'detail' => 'Starting '.$first->format('D M j').', then '.$first->addDays(14)->format('M j').', '.$first->addDays(28)->format('M j'),
                ];
            }
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
        $person?->setAttribute('display_name', $person && $viewer->canSee($person) ? $person->name : $person?->shortName());

        return $person;
    }

    /**
     * The typed search and its matches: active people who aren't already regulars on this slot and whose name contains it. Someone who can manage every area also matches on email; anyone else (an area lead) matches by name only, and an email is shown only for people they may see (spec section 13).
     *
     * @return array{query: string, tooShort: bool, matches: Collection<int, User>}
     */
    private function search(Request $request, User $viewer, Collection $current, bool $byEmail): array
    {
        $query = trim((string) $request->query('q', ''));

        if ($query === '' || mb_strlen($query) < self::MIN_SEARCH) {
            return ['query' => $query, 'tooShort' => $query !== '', 'matches' => collect()];
        }

        $like = '%'.addcslashes($query, '%_\\').'%';
        $matches = User::query()->whereNull('deactivated_at')
            ->whereNotIn('id', $current->pluck('user_id'))
            ->where(fn ($q) => $q->where('name', 'like', $like)->when($byEmail, fn ($q) => $q->orWhere('email', 'like', $like)))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'email'])
            ->each(function (User $match) use ($viewer) {
                $sees = $viewer->canSee($match);
                $match->setAttribute('show_email', $sees);
                $match->setAttribute('display_name', $sees ? $match->name : $match->shortName());
            });

        return ['query' => $query, 'tooShort' => false, 'matches' => $matches];
    }
}
