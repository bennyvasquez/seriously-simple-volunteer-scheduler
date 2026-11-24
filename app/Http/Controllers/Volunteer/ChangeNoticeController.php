<?php

namespace App\Http\Controllers\Volunteer;

use App\Actions\Scheduling\ConfirmChangeNotice;
use App\Calendar\TimeFormat;
use App\Http\Controllers\Controller;
use App\Models\Assignment;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Notifications\ShiftTimeChangedNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

/**
 * The answer page for a change notice (wireframes 57 and 58): "Does the new time still work for you?" for one person's batch, with "Still works for me" as a form POST and "I can't make the new time" as a link to "Can't make it" for the earliest shift. Both need a login. Someone else's batch answers exactly like one that doesn't exist (404), and a POST for it confirms nothing. The page says what happened when the batch can't be answered: confirmed already, replaced by a newer change, changed since (the person left the shift and was put back), the person is off the shift, the shift was cancelled, or it started. Home and the volunteer shift panel ask the same question with the same POST (`from` says where to come back to).
 */
class ChangeNoticeController extends Controller
{
    public const THANKS = 'Thanks. You\'re still on at the new time.';

    public function show(Request $request, string $batch): View
    {
        $user = $request->user();
        $rows = ChangeNotice::batchFor($batch, $user);

        abort_if($rows->isEmpty(), 404);

        $now = now();
        $onIt = Assignment::query()->where('user_id', $user->id)->whereIn('shift_id', $rows->pluck('shift_id'))->pluck('shift_id');
        $on = $rows->filter(fn (ChangeNotice $row) => $onIt->contains($row->shift_id));
        $ahead = $on->filter(fn (ChangeNotice $row) => $row->shift->cancelled_at === null && $row->shift->starts_at->greaterThan($now));
        // A newer notice for the same person and shift means a later change replaced this one.
        $newer = ChangeNotice::query()->where('user_id', $user->id)->whereIn('shift_id', $rows->pluck('shift_id'))->where('batch', '!=', $batch)
            ->where('id', '>', $rows->min('id'))->get(['id', 'shift_id']);
        $replaced = fn (ChangeNotice $row) => $newer->contains(fn ($other) => $other->shift_id === $row->shift_id && $other->id > $row->id);
        $open = $ahead->filter(fn (ChangeNotice $row) => $row->confirmed_at === null && $row->ended_at === null && ! $replaced($row))->values();
        $confirmed = $ahead->filter(fn (ChangeNotice $row) => $row->confirmed_at !== null && ! $replaced($row))->values();

        [$mode, $shown] = match (true) {
            $on->isEmpty() => ['gone', $rows],
            // Cancelling keeps people on a shift, so a cancelled one is told apart from one that started.
            $ahead->isEmpty() => [$on->contains(fn (ChangeNotice $row) => $row->shift->cancelled_at !== null) ? 'cancelled' : 'started', $on->values()],
            $open->isNotEmpty() => ['open', $open],
            $confirmed->isNotEmpty() => [$request->session()->pull('change_confirmed') ? 'thanks' : 'confirmed', $confirmed],
            $ahead->contains(fn (ChangeNotice $row) => $replaced($row)) => ['replaced', $ahead->values()],
            // Ended without a newer notice, and the person is on the shift again (they left and were put back).
            default => ['changed', $ahead->values()],
        };

        $summary = self::summary($shown);

        return view('volunteer.change-notice', [
            'mode' => $mode,
            'summary' => $summary,
            'batch' => $batch,
            'cantUrl' => route('shifts.release', $shown->first()->shift_id),
            'shiftUrl' => route('calendar.shift', $shown->first()->shift_id),
        ]);
    }

    public function confirm(Request $request, ConfirmChangeNotice $confirmChangeNotice, string $batch): RedirectResponse
    {
        $user = $request->user();

        abort_unless(ChangeNotice::query()->where('batch', $batch)->where('user_id', $user->id)->exists(), 404);

        $count = $confirmChangeNotice->handle($user, $batch);

        if ($count === 0) {
            // Nothing to confirm: the page says why.
            return redirect()->route('changes.show', $batch);
        }

        if ($request->input('from') === 'home') {
            return redirect()->route('home')->with('status', self::THANKS);
        }

        $shift = $request->input('from') === 'shift' ? Shift::query()->find((int) $request->input('shift')) : null;

        if ($shift !== null) {
            return redirect()->route('calendar.shift', $shift)->with('status', self::THANKS);
        }

        return redirect()->route('changes.show', $batch)->with('change_confirmed', true);
    }

    /**
     * What a batch says to the person, from its rows (earliest shift first, shift and area loaded): the heading, the new and old times, the dates and who changed it. When every shift has the same new and old time there's one "New time" and one "Was" and the first date; otherwise each date has its own line.
     *
     * @param  Collection<int, ChangeNotice>  $rows
     *                                               `which` says which shifts a yes covers, for the question on the schedule panel: "your Thursdays from October 22 to December 10" when the rows are every date of one weekly slot between the first and the last (the email's range wording), otherwise "{n} of your shifts".
     * @return array{heading: string, same: bool, now: string, was: string, date: string, count: int, which: string, lines: list<array{date: string, now: string, was: string}>, changedBy: ?string, earliest: Shift}
     */
    public static function summary(Collection $rows): array
    {
        $timezone = Organization::current()->timezone;
        $lines = $rows->map(function (ChangeNotice $row) use ($timezone) {
            $shift = $row->shift;

            return [
                'date' => $shift->local_date->format('l, F j'),
                'now' => TimeFormat::range($shift->starts_at->setTimezone($timezone), $shift->ends_at->setTimezone($timezone)),
                'was' => TimeFormat::range($row->was_starts_at->setTimezone($timezone), $row->was_ends_at->setTimezone($timezone)),
            ];
        })->values();
        $first = $rows->first()->shift;
        $sameArea = $rows->pluck('shift.area_id')->unique()->count() === 1;
        $changedBy = $rows->first()->changedBy;

        return [
            'heading' => match (true) {
                $rows->count() === 1 => 'Your '.$first->local_date->format('l').' '.$first->area->name.' shift changed',
                $sameArea => 'Your '.$first->area->name.' shifts changed',
                default => 'Your shifts changed',
            },
            'same' => $lines->pluck('now')->unique()->count() === 1 && $lines->pluck('was')->unique()->count() === 1,
            'now' => $lines->first()['now'],
            'was' => $lines->first()['was'],
            'date' => $lines->first()['date'],
            'count' => $rows->count(),
            'which' => $rows->count() > 1 && ShiftTimeChangedNotification::consecutiveDatesOfOneSlot($rows->map(fn (ChangeNotice $row) => $row->shift))
                ? 'your '.$first->local_date->format('l').'s from '.$first->local_date->format('F j').' to '.$rows->last()->shift->local_date->format('F j')
                : $rows->count().' of your shifts',
            'lines' => $lines->all(),
            'changedBy' => $changedBy === null || $changedBy->is_removed_person ? null : $changedBy->shortName(),
            'earliest' => $first,
        ];
    }
}
