<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\PutOnShift;
use App\Enums\AssignmentType;
use App\Enums\SlotFrequency;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * One shift's details beside the week (screen 32), with putting someone on it and cancelling the date. Access to the calendar is checked by the route's can:viewAny middleware; each action is checked against the shift's area.
 */
class ShiftController extends Controller
{
    use KeepsAreaFilter;

    private const MIN_SEARCH = 2;

    public function show(Request $request, CalendarController $calendar, Shift $shift): View
    {
        $shift->load(['area', 'assignments.user', 'weeklySlot.regulars']);
        $user = $request->user();
        $date = $shift->local_date->toDateString();
        $request->query->set('date', $date);

        $open = $shift->cancelled_at === null && $shift->starts_at->greaterThan(now());
        $canAssign = $open && Gate::forUser($user)->allows('assign', $shift);
        $canCancel = $open && Gate::forUser($user)->allows('cancel', $shift);
        $search = $this->search($request, $shift);

        return view('calendar.shift', $calendar->week($request)->getData() + [
            'shift' => $shift,
            'when' => $shift->whenText(),
            'repeats' => $this->repeats($shift),
            'people' => $this->people($shift),
            'shadowsTaken' => $shift->assignments->where('type', AssignmentType::Shadow)->count(),
            'canAssign' => $canAssign,
            'canCancel' => $canCancel,
            'picking' => $canAssign && ($request->boolean('pick') || $search['query'] !== ''),
            'cancelling' => $canCancel && $request->boolean('cancel'),
            'search' => $search,
        ]);
    }

    public function assign(Request $request, PutOnShift $putOnShift, Shift $shift): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('assign', $shift);

        $data = $request->validate([
            'person' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:regular,shadow'],
        ], ['person.required' => 'Pick a person.', 'person.exists' => 'Pick a person.', 'person.integer' => 'Pick a person.', 'type.*' => 'Pick regular or shadow.']);

        $areaQuery = $this->areaQuery($request);
        $person = User::query()->findOrFail($data['person']);

        try {
            $putOnShift->handle($request->user(), $shift, $person, AssignmentType::from($data['type']));
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        $first = explode(' ', trim($person->name))[0];

        return redirect()->route('calendar.shift', [$shift] + $areaQuery)
            ->with('status', $first.($data['type'] === 'shadow' ? ' is shadowing this shift.' : ' is on this shift.').' They were emailed.');
    }

    public function cancel(Request $request, CancelShift $cancelShift, Shift $shift): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('cancel', $shift);

        $areaQuery = $this->areaQuery($request);
        $reason = trim((string) $request->input('reason'));

        if ($reason === '' || mb_strlen($reason) > 200) {
            return redirect()->route('calendar.shift', [$shift, 'cancel' => 1] + $areaQuery)->withInput()
                ->withErrors(['reason' => $reason === '' ? 'Say why it\'s cancelled, so the people on it know.' : 'Keep it to 200 characters or fewer.']);
        }

        $people = $shift->assignments()->count();

        try {
            $cancelShift->handle($request->user(), $shift, $reason);
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        $shift->loadMissing('area');

        return redirect()->route('calendar.week', ['date' => $shift->local_date->toDateString()] + $areaQuery)
            ->with('status', 'Cancelled the '.$shift->area->name.' shift on '.$shift->local_date->format('l, F j').'. '.match ($people) {
                0 => 'Nobody was on it.',
                1 => '1 person was emailed.',
                default => $people.' people were emailed.',
            });
    }

    /** "Repeats every Thursday, since Sep 3. No end date." or "One time". */
    private function repeats(Shift $shift): string
    {
        $slot = $shift->weeklySlot;

        if ($slot === null) {
            return 'One time';
        }

        $weekday = CarbonImmutable::parse('2026-10-18', 'UTC')->addDays($slot->weekday)->format('l');
        $every = $slot->frequency === SlotFrequency::EveryOtherWeek ? 'every other '.$weekday : 'every '.$weekday;

        return 'Repeats '.$every.', since '.$slot->valid_from->format('M j').'. '.($slot->valid_until === null ? 'No end date.' : 'Ends '.$slot->valid_until->format('M j').'.');
    }

    /** @return Collection<int, array{name: string, role: string}> */
    private function people(Shift $shift): Collection
    {
        $date = $shift->local_date->toDateString();
        $slot = $shift->weeklySlot;

        return $shift->assignments->sortBy([['type', 'asc'], ['id', 'asc']])->map(function (Assignment $assignment) use ($slot, $date) {
            if ($assignment->type === AssignmentType::Shadow) {
                return ['name' => $assignment->user->name, 'role' => 'Shadowing'];
            }

            $regular = $slot?->regulars->first(fn (SlotRegular $regular) => $regular->user_id === $assignment->user_id && $regular->activeOn($date));

            if ($regular === null) {
                return ['name' => $assignment->user->name, 'role' => 'Regular'];
            }

            // On a slot that already runs every other week, its regulars work every date it runs.
            $everyOther = $regular->frequency === SlotFrequency::EveryOtherWeek || $slot->frequency === SlotFrequency::EveryOtherWeek;

            return ['name' => $assignment->user->name, 'role' => $everyOther ? 'Regular, every other week' : 'Regular, every week'];
        })->values();
    }

    /**
     * The typed search and its matches: active people not already on this shift whose name or email contains it.
     *
     * @return array{query: string, tooShort: bool, matches: Collection<int, User>}
     */
    private function search(Request $request, Shift $shift): array
    {
        $query = trim((string) $request->query('q', ''));
        $matches = collect();

        if ($query === '' || mb_strlen($query) < self::MIN_SEARCH || ! Gate::forUser($request->user())->allows('assign', $shift)) {
            return ['query' => $query, 'tooShort' => $query !== '' && mb_strlen($query) < self::MIN_SEARCH, 'matches' => $matches];
        }

        $like = '%'.addcslashes($query, '%_\\').'%';
        $matches = User::query()->whereNull('deactivated_at')
            ->whereNotIn('id', $shift->assignments->pluck('user_id'))
            ->where(fn ($q) => $q->where('name', 'like', $like)->orWhere('email', 'like', $like))
            ->orderBy('name')->limit(8)->get(['id', 'name', 'email']);

        return ['query' => $query, 'tooShort' => false, 'matches' => $matches];
    }
}
