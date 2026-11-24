<?php

namespace App\Actions\Scheduling;

use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftTimeChangedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Records change notices for a time change (spec section 10) and builds the emails asking each person whether the new time works. Call recordLocked() inside the edit's transaction after it holds the shift and assignment locks. It changes nothing but notices and the change log, and sends nothing: the caller sends the returned notifications after the response.
 *
 * For every person on a changed shift who can sign in (shadows too), it ends their open notices on that shift (replaced by this change), writes one row per shift under one new batch per person, and logs one `change_notice.sent` entry per person about them, with the batch, the shift ids and their count in `details`. Shifts that have started or are cancelled get nothing. Names go only in the entry's actor and affected person, never in details.
 */
class SendChangeNotices
{
    /**
     * @param  list<array{shift: Shift, was_starts_at: CarbonImmutable, was_ends_at: CarbonImmutable}>  $changes  Shifts whose start or end changed, carrying their new values and area.
     * @return list<array{user: User, notification: ShiftTimeChangedNotification}>
     */
    public function recordLocked(?User $actor, array $changes): array
    {
        $changes = collect($changes)->filter(fn (array $change) => $change['shift']->cancelled_at === null
            && $change['shift']->starts_at->greaterThan(now())
            && $change['was_starts_at']->greaterThan(now()))->keyBy(fn (array $change) => $change['shift']->id);

        if ($changes->isEmpty()) {
            return [];
        }

        $people = Assignment::query()->with('user')->whereIn('shift_id', $changes->keys())
            ->orderBy('id')->get()->filter(fn (Assignment $assignment) => $assignment->user->canSignIn())
            ->groupBy('user_id');

        $sent = [];

        foreach ($people as $assignments) {
            $person = $assignments->first()->user;
            $mine = $assignments->map(fn (Assignment $assignment) => $changes[$assignment->shift_id])
                ->sortBy(fn (array $change) => $change['shift']->starts_at->getTimestamp())->values();
            $batch = (string) Str::ulid();

            ChangeNotice::query()->open()->where('user_id', $person->id)
                ->whereIn('shift_id', $mine->map(fn (array $change) => $change['shift']->id))
                ->update(['ended_at' => now()]);

            foreach ($mine as $change) {
                ChangeNotice::query()->create([
                    'batch' => $batch,
                    'user_id' => $person->id,
                    'shift_id' => $change['shift']->id,
                    'changed_by' => $actor?->id,
                    'was_starts_at' => $change['was_starts_at'],
                    'was_ends_at' => $change['was_ends_at'],
                ]);
            }

            ChangeLogEntry::record($actor, 'change_notice.sent', $mine->first()['shift'], [
                'batch' => $batch,
                'shift_ids' => $mine->map(fn (array $change) => $change['shift']->id)->all(),
                'count' => $mine->count(),
            ], about: $person);

            $sent[] = ['user' => $person, 'notification' => new ShiftTimeChangedNotification($batch, $mine, $actor?->shortName())];
        }

        return $sent;
    }
}
