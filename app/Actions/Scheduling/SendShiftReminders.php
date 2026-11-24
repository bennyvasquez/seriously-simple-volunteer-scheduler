<?php

namespace App\Actions\Scheduling;

use App\Cover\CoverFill;
use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftReminderNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The shift reminder (spec section 10): once the organization's `reminder_hours` before a shift starts, everyone on it (regulars, people who took it, shadows) who can sign in and hasn't turned reminders off gets one email. Off (null hours) sends nothing. Only places made at or before the reminder time count, so someone put on inside the window isn't reminded of a shift they just signed up for. Cancelled shifts and shifts that started get nothing. Each shift is handled in its own transaction under the project's lock order (area, shift, assignments): `reminded_at` is set on every due place whether or not an email goes out, so each is considered once, and one `shift.reminded` entry is logged as the system when anyone was emailed (`details` hold the shift id and the count; names stay out). The emails go out after the commit. run() returns the number of emails sent.
 */
class SendShiftReminders
{
    public function run(): int
    {
        $hours = Organization::current()->reminder_hours;

        if ($hours === null) {
            return 0;
        }

        $now = CarbonImmutable::now('UTC');

        // Found without locks; each shift is read again under its locks below. The EXISTS keeps shifts whose places were all made inside the window from being locked every run.
        $candidates = Shift::query()
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours($hours))
            ->whereExists(fn ($places) => $places->selectRaw('1')->from('assignments')
                ->whereColumn('assignments.shift_id', 'shifts.id')
                ->whereNull('assignments.reminded_at')
                ->whereRaw('assignments.created_at <= DATE_SUB(shifts.starts_at, INTERVAL '.$hours.' HOUR)'))
            ->orderBy('starts_at')->orderBy('id')
            ->get(['id', 'area_id']);

        $sent = 0;

        foreach ($candidates as $candidate) {
            $sends = DB::transaction(fn () => $this->remindLocked($candidate, $hours));

            if ($sends === []) {
                continue;
            }

            $sent += count($sends);

            try {
                CoverFill::sender($sends)();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $sent;
    }

    /** @return list<array{0: User, 1: ShiftReminderNotification}> The emails to send after the commit, none when nobody is due or nobody can be emailed. */
    private function remindLocked(Shift $candidate, int $hours): array
    {
        $now = CarbonImmutable::now('UTC');

        // Locks in the project's order: area, shift, then the assignments.
        Area::query()->whereKey($candidate->area_id)->lockForUpdate()->first();
        $shift = Shift::query()->with('area')->whereKey($candidate->id)->lockForUpdate()->first();

        if ($shift === null || $shift->cancelled_at !== null || $shift->starts_at->lessThanOrEqualTo($now) || $shift->starts_at->greaterThan($now->addHours($hours))) {
            return [];
        }

        $cutoff = $shift->starts_at->subHours($hours);
        $places = Assignment::query()->with('user')->where('shift_id', $shift->id)->orderBy('id')->lockForUpdate()->get();
        $due = $places->filter(fn (Assignment $place) => $place->reminded_at === null && $place->created_at->lessThanOrEqualTo($cutoff))->values();

        if ($due->isEmpty()) {
            return [];
        }

        // Set whether or not an email goes out, so a person who can't be emailed isn't looked at again.
        DB::table('assignments')->whereIn('id', $due->modelKeys())->update(['reminded_at' => $now]);

        $recipients = $due->filter(fn (Assignment $place) => $place->user->canSignIn() && $place->user->reminder_emails)->values();

        if ($recipients->isEmpty()) {
            return [];
        }

        ChangeLogEntry::record(null, 'shift.reminded', $shift, [
            'shift_id' => $shift->id,
            'count' => $recipients->count(),
        ]);

        $names = fn (AssignmentType $type, User $except) => $places->filter(fn (Assignment $place) => $place->type === $type && $place->user_id !== $except->id)
            ->map(fn (Assignment $place) => $place->user->releaserName())->sort()->values()->all();
        $notices = ChangeNotice::query()->open()->where('shift_id', $shift->id)->whereIn('user_id', $recipients->pluck('user_id'))
            ->orderBy('id')->get()->keyBy('user_id');

        return $recipients->map(fn (Assignment $place) => [
            $place->user,
            new ShiftReminderNotification($shift, $place, $names(AssignmentType::Regular, $place->user), $names(AssignmentType::Shadow, $place->user), $notices->get($place->user_id)?->batch),
        ])->all();
    }
}
