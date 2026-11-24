<?php

namespace App\Http\Controllers\Volunteer;

use App\Actions\Scheduling\ReleaseShifts;
use App\Calendar\TimeFormat;
use App\Enums\AssignmentType;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * "Can't make it" (wireframes 2, 39 to 41): the form asking which shifts, the check step for several dates or the weekly slot, and "You're off". Nothing is picked to start with (UX principle 2). Several dates and the weekly slot are always checked first, on a second screen rendered from the post, and released only when that screen posts the same fields back with `confirm=1`. The note is free text, so it never goes in a URL. Every refusal from ReleaseShifts shows on the form; one that means the shift is no longer this person's to release (they're off it, or it was cancelled or has started) goes to Home as a status, since there's no form to show.
 */
class ReleaseController extends Controller
{
    public const PICK_SCOPE = 'Pick which shifts you can\'t make.';

    public const NOT_ON_IT = 'You\'re not on this shift any more.';

    public function show(Request $request, Shift $shift): View|RedirectResponse
    {
        $user = $request->user();
        $refusal = $this->refusal($user, $shift);

        if ($refusal !== null) {
            return redirect()->route('home')->with('status', $refusal);
        }

        $assignment = $this->assignment($user, $shift);

        if ($assignment === null) {
            return redirect()->route('home')->with('status', self::NOT_ON_IT);
        }

        $shift->load(['area', 'weeklySlot', 'assignments.user']);
        $date = $shift->local_date->toDateString();
        $shadow = $assignment->type === AssignmentType::Shadow;

        // The shadows who'd hear their spot is on hold: only when this person is the last one filling a place.
        $needsCover = ! $shadow && $this->needsCover($shift, $assignment);
        $shadowNames = $needsCover && $shift->filledCount() === 1
            ? $shift->assignments->where('type', AssignmentType::Shadow)->sortBy('id')->pluck('user')->filter(fn (User $person) => $person->canSignIn())->map(fn (User $person) => $person->shortName())->values()->all()
            : [];

        return view('volunteer.release', [
            'shift' => $shift,
            'card' => self::card($shift),
            'shadow' => $shadow,
            'shadowNames' => $shadowNames,
            'needsCover' => $needsCover,
            'dayName' => $shift->local_date->format('l, F j'),
            'regular' => $shadow ? null : $this->regular($user, $shift),
            'values' => [
                'scope' => (string) $request->old('scope', ''),
                'from' => (string) $request->old('from', $date),
                'to' => (string) $request->old('to', $date),
                'starting' => (string) $request->old('starting', $date),
                'note' => (string) $request->old('note', ''),
            ],
        ]);
    }

    public function store(Request $request, ReleaseShifts $releaseShifts, Shift $shift): View|RedirectResponse
    {
        $user = $request->user();
        $refusal = $this->refusal($user, $shift);

        if ($refusal !== null) {
            return redirect()->route('home')->with('status', $refusal);
        }

        $fields = ['scope', 'from', 'to', 'starting', 'note'];

        if ($request->boolean('change')) {
            return redirect()->route('shifts.release', $shift)->withInput($request->only($fields));
        }

        $back = fn (string $field, string $message) => redirect()->route('shifts.release', $shift)->withInput($request->only($fields))->withErrors([$field => $message]);
        $shift->load(['area', 'weeklySlot']);
        $note = (string) $request->input('note', '');

        try {
            $note = ReleaseShifts::cleanNote($note);
        } catch (DomainException $e) {
            return $back('note', $e->getMessage());
        }

        $assignment = $this->assignment($user, $shift);

        if ($assignment === null) {
            return redirect()->route('home')->with('status', self::NOT_ON_IT);
        }

        $isShadow = $assignment->type === AssignmentType::Shadow;
        $scope = $isShadow ? 'one' : (string) $request->input('scope');
        $confirmed = $request->boolean('confirm');

        if ($scope === 'one') {
            try {
                $releaseShifts->one($user, $shift, $note);
            } catch (DomainException $e) {
                return $back('scope', $e->getMessage());
            }

            return $this->toReleased($user, collect([$shift]), $isShadow ? [$shift->id] : [], $releaseShifts->nobodyAsked);
        }

        if ($scope === 'range') {
            $from = trim((string) $request->input('from'));
            $to = trim((string) $request->input('to'));

            try {
                $preview = $releaseShifts->rangePreview($user, $from, $to);
            } catch (DomainException $e) {
                return $back('dates', $e->getMessage());
            }

            if ($preview['shifts']->isEmpty()) {
                return $back('dates', 'You\'re not on any shifts on those dates.');
            }

            if (! $confirmed) {
                $stays = $this->staysRegular($user, $preview['shifts'], $to);

                return view('volunteer.release-check', [
                    'shift' => $shift,
                    'shifts' => $this->checkCards($user, $preview['shifts']),
                    'range' => self::dayText($from).' to '.self::dayText($to),
                    'regularLine' => $stays,
                    'lastOnCalendar' => $preview['lastOnCalendar'] === null ? null : self::dayText($preview['lastOnCalendar']),
                    'note' => $note,
                    'hidden' => ['scope' => 'range', 'from' => $from, 'to' => $to, 'note' => (string) $note],
                ]);
            }

            $shadowIds = $this->shadowIds($user, $preview['shifts']);

            try {
                $released = $releaseShifts->range($user, $from, $to, $note);
            } catch (DomainException $e) {
                return $back('dates', $e->getMessage());
            }

            return $this->toReleased($user, $released, $shadowIds, $releaseShifts->nobodyAsked);
        }

        if ($scope === 'slot') {
            $regular = $this->regular($user, $shift);
            $starting = trim((string) $request->input('starting'));

            if ($regular === null) {
                return $back('scope', 'That isn\'t your weekly slot.');
            }

            try {
                $preview = $releaseShifts->slotPreview($user, $regular, $starting);
            } catch (DomainException $e) {
                return $back('starting', $e->getMessage());
            }

            $noLonger = 'You\'re no longer the '.$regular->weeklySlot->weekdayName().' regular from '.self::dayText($starting).'.';

            if (! $confirmed) {
                return view('volunteer.release-check', [
                    'shift' => $shift,
                    'shifts' => $this->checkCards($user, $preview),
                    'range' => null,
                    'regularLine' => $noLonger,
                    'lastOnCalendar' => null,
                    'note' => $note,
                    'hidden' => ['scope' => 'slot', 'starting' => $starting, 'note' => (string) $note],
                ]);
            }

            try {
                $released = $releaseShifts->slot($user, $regular, $starting, $note);
            } catch (DomainException $e) {
                return $back('starting', $e->getMessage());
            }

            return $this->toReleased($user, $released, [], $releaseShifts->nobodyAsked, $noLonger, $regular->weeklySlot->weekdayName());
        }

        return $back('scope', self::PICK_SCOPE);
    }

    /** "You're off", from what the release put in the session flash. The shifts may not be this person's any more, so nothing here reads them again. Without a flash (a reload, or a typed URL) it goes to Home. */
    public function released(Request $request, HomeController $home): View|RedirectResponse
    {
        $released = $request->session()->get('released');

        if (! is_array($released)) {
            return redirect()->route('home');
        }

        $user = $request->user();

        return view('volunteer.released', [
            'released' => $released,
            'next' => $home->nextShifts($user),
            'timezone' => Organization::current()->timezone,
        ]);
    }

    /**
     * Why this person can't release their place on this shift, as a status for Home, or null when they can. The policy says whether they're on it; ReleaseShifts checks again under its locks.
     */
    private function refusal(User $user, Shift $shift): ?string
    {
        if (! Gate::forUser($user)->allows('release', $shift)) {
            abort_unless($user->isActive(), 403);

            return self::NOT_ON_IT;
        }

        if ($shift->cancelled_at !== null) {
            return 'This shift was cancelled.';
        }

        if ($shift->starts_at->lessThanOrEqualTo(now())) {
            return 'This shift has already started.';
        }

        return null;
    }

    /** The person's place on the shift, or null when it went between the policy check and here (another tab released it). */
    private function assignment(User $user, Shift $shift): ?Assignment
    {
        return $shift->assignments()->where('user_id', $user->id)->first();
    }

    /**
     * Whether a place opens that someone has to cover when this person comes off the shift: they hold a regular place and, without it, the shift would have fewer people than it needs. The same rule ReleaseShifts uses (openPlaces() above 0 once their place is gone), worked out here before anything changes. A shadow never opens a place, and a cancelled shift has none to fill.
     */
    private function needsCover(Shift $shift, ?Assignment $assignment): bool
    {
        if ($assignment === null || $assignment->type !== AssignmentType::Regular || $shift->cancelled_at !== null) {
            return false;
        }

        return $shift->people_needed - ($shift->assignments()->where('type', AssignmentType::Regular)->count() - 1) > 0;
    }

    /**
     * The cards for the check step, each with `shadow` true for a shadow spot and `nobodyAsked` true when nobody is asked to cover it: a shadow spot, or a shift that keeps the people it needs without this person (so nobody is asked to cover it).
     *
     * @param  Collection<int, Shift>  $shifts
     * @return list<array{date: string, area: string, what: string, shadow: bool, nobodyAsked: bool}>
     */
    private function checkCards(User $user, Collection $shifts): array
    {
        $mine = Assignment::query()->where('user_id', $user->id)->whereIn('shift_id', $shifts->modelKeys())->get()->keyBy('shift_id');
        $regulars = Assignment::query()->whereIn('shift_id', $shifts->modelKeys())->where('type', AssignmentType::Regular)->selectRaw('shift_id, count(*) as total')->groupBy('shift_id')->pluck('total', 'shift_id');

        return $shifts->map(function (Shift $listed) use ($mine, $regulars) {
            $assignment = $mine->get($listed->id);
            $isShadow = $assignment?->type === AssignmentType::Shadow;
            $covered = $assignment?->type === AssignmentType::Regular && $listed->cancelled_at === null && $listed->people_needed - ((int) $regulars->get($listed->id, 0) - 1) > 0;

            return self::card($listed) + ['shadow' => $isShadow, 'nobodyAsked' => ! $covered];
        })->values()->all();
    }

    /** The person's weekly regular for this shift's slot on its date, the one "Give up my weekly slot" ends, or null when they aren't its regular then. */
    private function regular(User $user, Shift $shift): ?SlotRegular
    {
        if ($shift->weekly_slot_id === null) {
            return null;
        }

        $date = $shift->local_date->toDateString();

        return SlotRegular::query()->with('weeklySlot')->where('user_id', $user->id)->where('weekly_slot_id', $shift->weekly_slot_id)->orderBy('id')->get()
            ->first(fn (SlotRegular $regular) => $regular->activeOn($date));
    }

    /**
     * "You stay the Thursday regular. From Thursday, December 24 you're back on as usual." for the weekly slots the person is the regular for on the listed dates, or null when there are none. The second sentence is there only for one slot, when its next date after the range is within four weeks.
     *
     * @param  Collection<int, Shift>  $shifts
     */
    private function staysRegular(User $user, Collection $shifts, string $to): ?string
    {
        $regulars = $this->regularsOn($user, $shifts);

        if ($regulars->isEmpty()) {
            return null;
        }

        if ($regulars->count() > 1) {
            return 'You stay the regular on your weekly shifts.';
        }

        $regular = $regulars->first();
        $line = 'You stay the '.$regular->weeklySlot->weekdayName().' regular.';
        $after = CarbonImmutable::parse($to, 'UTC')->addDay();

        for ($day = $after; $day->lessThanOrEqualTo($after->addDays(28)); $day = $day->addDay()) {
            if ($regular->weeklySlot->occursOn($day->toDateString()) && $regular->activeOn($day->toDateString())) {
                return $line.' From '.$day->format('l, F j').' you\'re back on as usual.';
            }
        }

        return $line;
    }

    /**
     * The person's regulars, one per slot, active on at least one of these shifts' dates.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return Collection<int, SlotRegular>
     */
    private function regularsOn(User $user, Collection $shifts): Collection
    {
        $slotIds = $shifts->pluck('weekly_slot_id')->filter()->unique()->values();

        if ($slotIds->isEmpty()) {
            return collect();
        }

        $regulars = SlotRegular::query()->with('weeklySlot')->where('user_id', $user->id)->whereIn('weekly_slot_id', $slotIds)->orderBy('id')->get();

        return $regulars->filter(fn (SlotRegular $regular) => $shifts->contains(fn (Shift $shift) => $shift->weekly_slot_id === $regular->weekly_slot_id
            && $regular->activeOn($shift->local_date->toDateString())))->unique('weekly_slot_id')->values();
    }

    /**
     * The ids of these shifts where the person has a shadow spot, read before the release.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return list<int>
     */
    private function shadowIds(User $user, Collection $shifts): array
    {
        return Assignment::query()->where('user_id', $user->id)->whereIn('shift_id', $shifts->modelKeys())->where('type', AssignmentType::Shadow)
            ->pluck('shift_id')->values()->all();
    }

    /**
     * Puts what was released in the session flash and goes to "You're off". The shadows named are the ones told their spot is on hold: on a regular place, when nobody but shadows is left on the shift, and only those who can be emailed. `askedCount` counts the regular places someone was asked to cover; the others still had the people they need (`nobodyAsked` on their card).
     *
     * @param  Collection<int, Shift>  $released
     * @param  list<int>  $shadowIds
     * @param  list<int>  $nobodyAsked  ReleaseShifts::$nobodyAsked after the release.
     */
    private function toReleased(User $user, Collection $released, array $shadowIds, array $nobodyAsked, ?string $noLonger = null, ?string $slotWeekday = null): RedirectResponse
    {
        $regularShifts = $released->reject(fn (Shift $shift) => in_array($shift->id, $shadowIds, true))->values();
        $told = [];

        foreach ($regularShifts as $shift) {
            if ($shift->assignments()->where('type', AssignmentType::Regular)->exists()) {
                continue;
            }

            $shadows = $shift->assignments()->with('user')->where('type', AssignmentType::Shadow)->orderBy('id')->get()->pluck('user');
            array_push($told, ...$shadows->filter(fn (User $shadow) => $shadow->canSignIn())->map(fn (User $shadow) => $shadow->shortName())->all());
        }

        $weekdays = $noLonger === null ? $this->regularsOn($user, $regularShifts)->map(fn (SlotRegular $regular) => $regular->weeklySlot->weekdayName())->values() : collect();

        return redirect()->route('shifts.released')->with('released', [
            'shifts' => $released->map(fn (Shift $shift) => self::card($shift) + ['shadowSpot' => in_array($shift->id, $shadowIds, true), 'nobodyAsked' => in_array($shift->id, $nobodyAsked, true)])->values()->all(),
            'regularCount' => $regularShifts->count(),
            'askedCount' => $regularShifts->reject(fn (Shift $shift) => in_array($shift->id, $nobodyAsked, true))->count(),
            'shadows' => array_values(array_unique($told)),
            'regularLine' => $noLonger ?? match ($weekdays->count()) {
                0 => null,
                1 => 'You stay the '.$weekdays[0].' regular.',
                default => 'You stay the regular on your weekly shifts.',
            },
            'slotWeekday' => $slotWeekday,
        ]);
    }

    /** @return array{date: string, area: string, what: string} "Thursday, October 22" and "Front desk, 6:30 to 9:30pm", as the Home cards show them. */
    public static function card(Shift $shift): array
    {
        $timezone = Organization::current()->timezone;
        $shift->loadMissing('area');

        return [
            'date' => $shift->local_date->format('l, F j'),
            'area' => $shift->area->name,
            'what' => $shift->area->name.', '.TimeFormat::range($shift->starts_at->setTimezone($timezone), $shift->ends_at->setTimezone($timezone)),
        ];
    }

    /** A Y-m-d date as "Thursday, October 22". */
    private static function dayText(string $date): string
    {
        return CarbonImmutable::parse($date, 'UTC')->format('l, F j');
    }

    /** "Robin", "Robin and Jo". */
    public static function names(array $names): string
    {
        return Arr::join($names, ', ', ' and ');
    }

    /** Ends a sentence with a full stop, unless the text already ends in one. A short name like "Jordan P." has its own full stop, so a sentence that ends on a name shouldn't get a second. */
    public static function endSentence(string $text): string
    {
        return str_ends_with($text, '.') ? $text : $text.'.';
    }
}
