<?php

namespace App\Http\Controllers\Calendar;

use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\NeedsOverConfirmation;
use App\Actions\Scheduling\PutOnShift;
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
        $byEmail = PersonSearch::byEmail($user);
        $canAddRegular = $shift->weeklySlot !== null && ! $shift->weeklySlot->is_rotation && Gate::forUser($user)->allows('manageSchedule', $shift->area);
        // A regular can always be put on (past the people needed after a confirmation), so putting someone on is open to anyone who can assign.
        $canPut = $canAssign;
        $search = PersonSearch::find($user, (string) $request->query('q', ''), $byEmail, $shift->assignments->pluck('user_id'), $canPut);
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
            ? User::query()->whereNull('deactivated_at')->whereNotIn('id', $shift->assignments->pluck('user_id'))->find((int) $request->query('person'))
            : null;
        $over?->setAttribute('display_name', PersonSearch::displayName($user, $over));

        return view('calendar.shift', $calendar->week($request)->getData() + [
            'shift' => $shift,
            'when' => $shift->whenText(),
            'repeats' => $this->repeats($shift),
            'people' => $this->people($shift, $user),
            'shadowsTaken' => $shift->assignments->where('type', AssignmentType::Shadow)->count(),
            'canAssign' => $canAssign,
            'canCancel' => $canCancel,
            'canPut' => $canPut,
            'over' => $over,
            'canAddRegular' => $canAddRegular,
            'picking' => $canPut && ($request->boolean('pick') || $search['query'] !== ''),
            'cancelling' => $canCancel && $editing === null && $request->boolean('cancel'),
            'canEdit' => $canEdit,
            'canEditSlot' => $canEditSlot,
            'editing' => $editing,
            'staffedMessage' => $shift->assignments->isNotEmpty() ? UpdateShift::STAFFED : null,
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
            $putOnShift->handle($request->user(), $shift, $person, AssignmentType::from($data['type']), $request->boolean('over_confirmed'));
        } catch (NeedsOverConfirmation) {
            return redirect()->route('calendar.shift', [$shift, 'confirm' => 1, 'person' => $person->id, 'type' => $data['type']] + $areaQuery);
        } catch (DomainException $e) {
            return redirect()->route('calendar.shift', [$shift] + $areaQuery)->withErrors(['shift' => $e->getMessage()]);
        }

        $first = $person->firstName();

        return redirect()->route('calendar.shift', [$shift] + $areaQuery)
            ->with('status', $first.($data['type'] === 'shadow' ? ' is shadowing this shift.' : ' is on this shift.').($person->canSignIn() ? ' They were emailed.' : ''));
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

    /** @return Collection<int, array{name: string, role: string}> The full name only for people the viewer may see (spec section 13); a first name and last initial otherwise. */
    private function people(Shift $shift, User $viewer): Collection
    {
        $date = $shift->local_date->toDateString();
        $slot = $shift->weeklySlot;

        return $shift->assignments->sortBy([['type', 'asc'], ['id', 'asc']])->map(function (Assignment $assignment) use ($slot, $date, $viewer) {
            $name = PersonSearch::displayName($viewer, $assignment->user);

            if ($assignment->type === AssignmentType::Shadow) {
                return ['name' => $name, 'role' => 'Shadowing'];
            }

            $regular = $slot?->regulars->first(fn (SlotRegular $regular) => $regular->user_id === $assignment->user_id && $regular->activeOn($date));

            if ($regular === null) {
                return ['name' => $name, 'role' => 'Regular'];
            }

            // On a slot that already runs every other week, its regulars work every date it runs.
            $everyOther = $regular->frequency === SlotFrequency::EveryOtherWeek || $slot->frequency === SlotFrequency::EveryOtherWeek;

            return ['name' => $name, 'role' => $everyOther ? 'Regular, every other week' : 'Regular, every week'];
        })->values();
    }
}
