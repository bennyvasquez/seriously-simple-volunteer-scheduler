<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\MarkNoShow;
use App\Actions\Scheduling\NeedsOverConfirmation;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\RemoveFromShift;
use App\Actions\Scheduling\RestoreShift;
use App\Actions\Scheduling\UpdateShift;
use App\Calendar\PersonSearch;
use App\Enums\AssignmentType;
use App\Enums\SlotFrequency;
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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * One shift's details beside the week (screen 32), with putting someone on it, editing it or its weekly slot, and cancelling the date. Access to the calendar is checked by the route's can:viewAny middleware; each action is checked against the shift's area.
 */
class ShiftController extends Controller
{
    use KeepsAreaFilter;

    public function show(Request $request, CalendarController $calendar, Shift $shift): View|RedirectResponse
    {
        $shift->load(['area', 'assignments.user', 'weeklySlot.regulars']);
        $user = $request->user();
        $date = $shift->local_date->toDateString();
        $request->query->set('date', $date);

        $open = $shift->cancelled_at === null && $shift->starts_at->greaterThan(now());
        $canAssign = $open && Gate::forUser($user)->allows('assign', $shift);
        $canCancel = $open && Gate::forUser($user)->allows('cancel', $shift);
        // A cancelled shift that hasn't started: who cancelled it decides whether it can be brought back here.
        $cancelledAhead = $shift->cancelled_at !== null && $shift->starts_at->greaterThan(now());
        $cancelledBy = $cancelledAhead ? RestoreShift::cancelledBy($shift) : null;
        $blocked = $cancelledBy === RestoreShift::HAND ? RestoreShift::blockedBy($shift) : null;
        $canRestore = $cancelledBy === RestoreShift::HAND && $blocked === null && Gate::forUser($user)->allows('restore', $shift);
        $closure = $cancelledBy === RestoreShift::CLOSURE ? $shift->areaClosure : null;
        $byEmail = PersonSearch::byEmail($user);
        $canAddRegular = $shift->weeklySlot !== null && ! $shift->weeklySlot->is_rotation && Gate::forUser($user)->allows('manageSchedule', $shift->area);
        // A regular can always be put on (past the people needed after a confirmation), so putting someone on is open to anyone who can assign.
        $canPut = $canAssign;
        // Only people in a regular place are left out. A shadow is listed as "Shadowing", and putting them on moves them to a regular place (#21).
        $regularIds = $shift->assignments->where('type', AssignmentType::Regular)->pluck('user_id');
        $shadowIds = $shift->assignments->where('type', AssignmentType::Shadow)->pluck('user_id');
        $search = PersonSearch::find($user, (string) $request->query('q', ''), $byEmail, $regularIds, $canPut);
        $search['matches']->each(fn (User $match) => $match->setAttribute('shadowing', $shadowIds->contains($match->id)));
        $canEdit = $open && Gate::forUser($user)->allows('update', $shift);
        $canEditSlot = $canEdit && $shift->weeklySlot !== null && Gate::forUser($user)->allows('manageSchedule', $shift->area);
        $edit = $canEdit ? (string) $request->query('edit', '') : '';

        if ($edit === 'slot' && $canEditSlot) {
            return redirect()->route('calendar.slots.edit', [$shift->weeklySlot] + $this->areaQuery($request));
        }

        // Edit on a repeating shift first asks which shifts to change; a one-time shift goes straight to its form.
        $editing = match (true) {
            $edit === 'shift', $edit !== '' && ! $canEditSlot => 'shift',
            $edit !== '' => 'choose',
            default => null,
        };

        // The confirm step for going over the people needed, from the person and type the coordinator picked.
        $over = $canPut && $request->boolean('confirm') && $request->query('type') === 'regular' && $shift->openPlaces() === 0
            ? User::query()->whereNull('deactivated_at')->whereNotIn('id', $regularIds)->find((int) $request->query('person'))
            : null;
        $over?->setAttribute('display_name', PersonSearch::displayName($user, $over));

        return view('calendar.shift', $calendar->week($request)->getData() + [
            'shift' => $shift,
            'when' => $shift->whenText(),
            'repeats' => $this->repeats($shift),
            'people' => $this->people($shift, $user, $canAddRegular),
            'shadowsTaken' => $shift->assignments->where('type', AssignmentType::Shadow)->count(),
            'canAssign' => $canAssign,
            'canCancel' => $canCancel,
            'canPut' => $canPut,
            'over' => $over,
            'canAddRegular' => $canAddRegular,
            'picking' => $canPut && ($request->boolean('pick') || $search['query'] !== ''),
            'cancelledBy' => $cancelledBy,
            'closure' => $closure,
            'canChangeClosure' => $closure !== null && Gate::forUser($user)->allows('close', $shift->area),
            'canRestore' => $canRestore,
            'restoreBlocked' => $blocked,
            'restoreCounts' => $canRestore && $request->boolean('restore') ? $this->restoreCounts($shift, $user) : null,
            'restoring' => $canRestore && $request->boolean('restore'),
            'cancelling' => $canCancel && $editing === null && $request->boolean('cancel'),
            'canEdit' => $canEdit,
            'canEditSlot' => $canEditSlot,
            'editing' => $editing,
            'editValues' => [
                'start_time' => (string) $request->old('start_time', $shift->starts_at->setTimezone(Organization::current()->timezone)->format('H:i')),
                'end_time' => (string) $request->old('end_time', $shift->ends_at->setTimezone(Organization::current()->timezone)->format('H:i')),
                'people_needed' => (string) $request->old('people_needed', $shift->people_needed),
                'shadow_spots' => (string) $request->old('shadow_spots', $shift->shadow_spots),
                'title' => (string) $request->old('title', (string) $shift->title),
            ],
            'search' => $search,
            'byEmail' => $byEmail,
            'emailCount' => $shift->assignments->filter(fn (Assignment $assignment) => $assignment->user->canSignIn())->count(),
        ]);
    }

    public function assign(Request $request, PutOnShift $putOnShift, Shift $shift): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('assign', $shift);

        $data = $request->validate([
            'person' => ['required', 'integer', 'exists:users,id'],
            'type' => ['required', 'in:regular,shadow'],
            'over_confirmed' => ['nullable', 'boolean'],
        ], ['person.required' => 'Pick a person.', 'person.exists' => 'Pick a person.', 'person.integer' => 'Pick a person.', 'type.*' => 'Pick regular or shadow.']);

        $areaQuery = $this->areaQuery($request);
        $person = User::query()->findOrFail($data['person']);

        try {
            $assignment = $putOnShift->handle($request->user(), $shift, $person, AssignmentType::from($data['type']), $request->boolean('over_confirmed'));
        } catch (NeedsOverConfirmation) {
            return redirect()->route('calendar.shift', [$shift, 'confirm' => 1, 'person' => $person->id, 'type' => $data['type']] + $areaQuery);
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        // An assignment that wasn't just created is a shadow place PutOnShift turned into a regular one.
        $placed = match (true) {
            $data['type'] === 'shadow' => ' is shadowing this shift.',
            ! $assignment->wasRecentlyCreated => ' is on this shift instead of shadowing it.',
            default => ' is on this shift.',
        };

        return redirect()->route('calendar.shift', [$shift] + $areaQuery)
            ->with('status', $person->firstName().$placed.($person->canSignIn() ? ' They were emailed.' : ''));
    }

    /** The confirmation page for removing one person from this shift, or from their shifts across a range of dates (neither choice picked to start with). "Check which shifts" comes back here with ?check=1 and the dates, so it works without JavaScript. */
    public function removePerson(Request $request, CalendarController $calendar, RemoveFromShift $removeFromShift, Shift $shift): View|RedirectResponse
    {
        $viewer = $request->user();
        Gate::forUser($viewer)->authorize('removePerson', $shift);

        $assignment = $shift->assignments()->with('user')->whereKey((int) $request->query('assignment'))->first();
        $areaQuery = $this->areaQuery($request);

        if ($assignment === null || $shift->starts_at->lessThanOrEqualTo(now())) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery);
        }

        $shift->load('area');
        $person = $assignment->user;
        $date = $shift->local_date->toDateString();
        $request->query->set('date', $date);

        $which = in_array($request->query('which'), ['this', 'range'], true) ? (string) $request->query('which') : '';
        $values = [
            'from' => (string) $request->query('from', $date),
            'to' => (string) $request->query('to', $date),
        ];
        $preview = null;
        $error = null;

        if ($which === 'range' && $request->boolean('check')) {
            try {
                $preview = $removeFromShift->rangePreview($viewer, $person, $values['from'], $values['to']);
            } catch (DomainException $e) {
                $error = $e->getMessage();
            }
        }

        return view('calendar.remove-from-shifts', $calendar->week($request)->getData() + [
            'shift' => $shift,
            'assignment' => $assignment,
            'person' => $person,
            'personName' => PersonSearch::displayName($viewer, $person),
            'which' => $which,
            'values' => $values,
            'preview' => $preview,
            'dateError' => $error,
        ]);
    }

    public function removePersonStore(Request $request, RemoveFromShift $removeFromShift, Shift $shift): RedirectResponse
    {
        $viewer = $request->user();
        Gate::forUser($viewer)->authorize('removePerson', $shift);

        $areaQuery = $this->areaQuery($request);
        $assignment = $shift->assignments()->with('user')->whereKey((int) $request->input('assignment'))->first();

        if ($assignment === null) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => 'That person isn\'t on this shift any more.']);
        }

        $person = $assignment->user;
        $which = (string) $request->input('which');
        $from = trim((string) $request->input('from'));
        $to = trim((string) $request->input('to'));
        $back = fn (array $extra = []) => redirect()->route('calendar.shift.remove-person', [$shift, 'assignment' => $assignment->id, 'which' => $which, 'from' => $from, 'to' => $to] + $extra + $areaQuery);

        if (! in_array($which, ['this', 'range'], true)) {
            return redirect()->route('calendar.shift.remove-person', [$shift, 'assignment' => $assignment->id] + $areaQuery)->withErrors(['which' => 'Pick just this date or a range of dates.']);
        }

        // A range is always checked first, and removed only with the dates that were checked.
        if ($request->has('check') || ($which === 'range' && (! $request->boolean('confirmed') || $request->input('checked_from') !== $from || $request->input('checked_to') !== $to))) {
            return $back(['check' => 1]);
        }

        try {
            if ($which === 'this') {
                $removeFromShift->one($viewer, $shift, $assignment);
                $removed = collect([$shift]);
            } else {
                $removed = $removeFromShift->range($viewer, $person, $from, $to);
            }
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        $count = $removed->count();
        $emailed = $person->canSignIn() && $removed->contains(fn (Shift $removedShift) => $removedShift->cancelled_at === null);

        return redirect()->route('calendar.shift', [$shift] + $areaQuery)
            ->with('status', $person->firstName().' was removed from '.$count.($count === 1 ? ' shift.' : ' shifts.').($emailed ? ' They were emailed.' : ''));
    }

    /** Saves the edit form for one shift. Errors go back to the form with what was typed. */
    public function update(Request $request, UpdateShift $updateShift, Shift $shift): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('update', $shift);

        $areaQuery = $this->areaQuery($request);

        try {
            $data = $request->validate([
                'title' => ['nullable', 'string', 'max:80'],
                'start_time' => ['required', 'date_format:H:i'],
                'end_time' => ['required', 'date_format:H:i', 'different:start_time'],
                'people_needed' => ['required', 'integer', 'min:1', 'max:200'],
                'shadow_spots' => ['required', 'integer', 'min:0', 'max:200'],
            ], [
                'title.max' => 'Keep it to 80 characters or fewer.',
                'start_time.*' => 'Pick a start time.',
                'end_time.different' => 'A shift has to end at a different time than it starts.',
                'end_time.*' => 'Pick an end time.',
                'people_needed.max' => 'That is more people than one shift can have. Use 200 or fewer.',
                'people_needed.*' => 'At least 1 person is needed.',
                'shadow_spots.max' => 'That is more shadow spots than one shift can have. Use 200 or fewer.',
                'shadow_spots.*' => 'Enter 0 if there are no shadow spots.',
            ]);

            $updateShift->handle($request->user(), $shift, ['title' => $data['title'] ?? null] + $data);
        } catch (ValidationException $e) {
            return redirect()->route('calendar.shift', [$shift, 'edit' => 'shift'] + $areaQuery)->withErrors($e->errors())->withInput();
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        return redirect()->route('calendar.shift', [$shift] + $areaQuery)->with('status', 'Saved the changes to this shift.');
    }

    public function noShow(Request $request, MarkNoShow $markNoShow, Shift $shift): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('update', $shift);

        $data = $request->validate(['assignment' => ['required', 'integer'], 'no_show' => ['required', 'boolean']]);
        $assignment = Assignment::query()->where('shift_id', $shift->id)->findOrFail($data['assignment']);

        try {
            $markNoShow->handle($request->user(), $assignment, (bool) $data['no_show']);
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $this->areaQuery($request))->withErrors(['shift' => $e->getMessage()]);
        }

        $name = PersonSearch::displayName($request->user(), $assignment->user);

        return redirect()->route('calendar.shift', [$shift] + $this->areaQuery($request))
            ->with('status', $data['no_show'] ? 'Marked '.$name.' as a no-show.' : 'Undid the no-show for '.$name.'.');
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

        try {
            $people = $cancelShift->handle($request->user(), $shift, $reason);
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        $shift->loadMissing('area');
        // Someone on the shift who can't be emailed (not invited yet) doesn't make "Nobody was on it" true, so that case says nothing.
        $sentence = match (true) {
            $people === 1 => '1 person was emailed.',
            $people > 1 => $people.' people were emailed.',
            $shift->assignments()->exists() => '',
            default => 'Nobody was on it.',
        };

        return redirect()->route('calendar.week', ['date' => $shift->local_date->toDateString()] + $areaQuery)
            ->with('status', trim('Cancelled the '.$shift->area->name.' shift on '.$shift->local_date->format('l, F j').'. '.$sentence));
    }

    /** @return array{emailed: int, removed: string, removedCount: int} Who a restore would email, including the slot's regulars it would add (see RestoreShift::preview()), and the names (as the viewer may see them) of people who would be removed for an overlap. */
    private function restoreCounts(Shift $shift, User $viewer): array
    {
        $preview = RestoreShift::preview($shift);
        $removed = $preview['removed']->map(fn (User $person) => PersonSearch::displayName($viewer, $person));

        return ['emailed' => $preview['staying']->filter(fn (User $person) => $person->canSignIn())->count(), 'removed' => $removed->join(', ', ' and '), 'removedCount' => $removed->count()];
    }

    public function restore(Request $request, RestoreShift $restoreShift, Shift $shift): RedirectResponse
    {
        Gate::forUser($request->user())->authorize('restore', $shift);

        $areaQuery = $this->areaQuery($request);

        try {
            $result = $restoreShift->handle($request->user(), $shift);
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        $shift->loadMissing('area');
        $emailed = $result['emailed'];
        $removed = $result['removed'];
        $viewer = $request->user();
        $names = $removed->map(fn (User $person) => PersonSearch::displayName($viewer, $person))->join(', ', ' and ');
        $sentence = $emailed === 0 ? '' : ($emailed === 1 ? '1 person was emailed.' : $emailed.' people were emailed.');

        if ($removed->isNotEmpty()) {
            $sentence = trim($sentence.' '.$names.($removed->count() === 1 ? ' was' : ' were').' removed from it, because they\'re now on another shift at that time.');
        }

        return redirect()->route('calendar.shift', [$shift] + $areaQuery)
            ->with('status', trim('Brought back the '.$shift->area->name.' shift on '.$shift->local_date->format('l, F j').'. '.$sentence));
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

    /** @return Collection<int, array{name: string, role: string, remove: ?string, end: ?string, assignment: int, noShow: bool, canMark: bool}> The full name only for people the viewer may see (spec section 13); a first name and last initial otherwise. */
    private function people(Shift $shift, User $viewer, bool $canManageSlot): Collection
    {
        $date = $shift->local_date->toDateString();
        $slot = $shift->weeklySlot;

        // The link to the remove page, for people who can remove from a shift that hasn't started.
        $canRemove = $shift->starts_at->greaterThan(now()) && Gate::forUser($viewer)->allows('removePerson', $shift);
        $remove = fn (Assignment $assignment) => $canRemove ? route('calendar.shift.remove-person', [$shift, 'assignment' => $assignment->id] + $this->areaQuery(request())) : null;

        // Shadows on a shift that has ended can be marked a no-show, or have it undone, by anyone who can edit the shift.
        $canMark = $shift->cancelled_at === null && $shift->ends_at->lessThanOrEqualTo(now()) && Gate::forUser($viewer)->allows('update', $shift);

        return $shift->assignments->sortBy([['type', 'asc'], ['id', 'asc']])->map(function (Assignment $assignment) use ($slot, $date, $viewer, $remove, $canManageSlot, $canMark) {
            $name = PersonSearch::displayName($viewer, $assignment->user);

            if ($assignment->type === AssignmentType::Shadow) {
                return ['name' => $name, 'role' => 'Shadowing', 'remove' => $remove($assignment), 'end' => null, 'assignment' => $assignment->id, 'noShow' => $assignment->no_show_at !== null, 'canMark' => $canMark];
            }

            $regular = $slot?->regulars->first(fn (SlotRegular $regular) => $regular->user_id === $assignment->user_id && $regular->activeOn($date));

            if ($regular === null) {
                return ['name' => $name, 'role' => 'Regular', 'remove' => $remove($assignment), 'end' => null];
            }

            // On a slot that already runs every other week, its regulars work every date it runs.
            $everyOther = $regular->frequency === SlotFrequency::EveryOtherWeek || $slot->frequency === SlotFrequency::EveryOtherWeek;

            // The link to end their time as a regular, for people who manage the slot's area.
            $end = $canManageSlot ? route('calendar.slots.regulars.end', [$slot, $regular, 'date' => $date] + $this->areaQuery(request())) : null;

            return ['name' => $name, 'role' => $everyOther ? 'Regular, every other week' : 'Regular, every week', 'remove' => $remove($assignment), 'end' => $end];
        })->values();
    }
}
