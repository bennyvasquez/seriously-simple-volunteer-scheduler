<?php

namespace App\Actions\Scheduling;

use App\Calendar\TimeFormat;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftChangedNotification;
use App\Notifications\ShiftTimeChangedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Records change notices for a time change (spec section 10) and builds the emails asking each person whether the new time works. Call recordLocked() inside the edit's transaction after it holds the shift and assignment locks. It changes nothing but notices and the change log, and sends nothing: the caller sends the returned notifications after the response.
 *
 * For every person on a changed shift who can sign in (shadows too), it ends their open notices on that shift (replaced by this change, and the new notice keeps the replaced notice's "was" time, the last time the person was asked about, not an unanswered time in between), writes one row per shift under one new batch per person, and logs one `change_notice.sent` entry per person about them, with the batch, the shift ids and their count in `details`. A shift that is back at the time the person was last asked about (a change undone before they answered) ends the replaced notice and gets no new one, and a person with nothing else changed in the edit gets no entry and no time email. A title change made with the undo is still told: as a line in the person's time email, or (when nothing else changed for them) through `$titleOnly`, which the caller sends as the plain title email. Shifts that have started or are cancelled get nothing. Names go only in the entry's actor and affected person, never in details.
 */
class SendChangeNotices
{
    /**
     * People whose only change in the last recordLocked() call was a time undone together with a title change, so they get no time email and the caller sends them the plain title email. Keyed by user id, in the shape of the callers' `$emails` lists: `['person' => User, 'changes' => list<array{shift: Shift, was: string, wasTitle: ?string}>]`. People who also have a time change get these lines in their time email instead.
     *
     * @var array<int, array{person: User, changes: list<array{shift: Shift, was: string, wasTitle: ?string}>}>
     */
    public array $titleOnly = [];

    /**
     * @param  list<array{shift: Shift, was_starts_at: CarbonImmutable, was_ends_at: CarbonImmutable, was_title?: ?string, back_on?: bool, except?: list<int>}>  $changes  Shifts whose start or end changed, carrying their new values and area. `back_on` is true for a shift that was cancelled and has come back at a new time, which the email says; `except` lists user ids who get no notice about that shift (regulars who joined it as it came back, who never knew the old time).
     * @return list<array{user: User, notification: ShiftTimeChangedNotification}>
     */
    public function recordLocked(?User $actor, array $changes): array
    {
        $this->titleOnly = [];

        // A shift that has started or is cancelled is never noticed. The old start has to be ahead too: a shift that was already under way or over can't be moved, so a notice for it would ask about a time that never applied.
        $changes = collect($changes)->filter(fn (array $change) => $change['shift']->cancelled_at === null
            && $change['shift']->starts_at->greaterThan(now())
            && $change['was_starts_at']->greaterThan(now()))->keyBy(fn (array $change) => $change['shift']->id);

        if ($changes->isEmpty()) {
            return [];
        }

        $people = Assignment::query()->with('user')->whereIn('shift_id', $changes->keys())
            ->orderBy('id')->get()
            ->filter(fn (Assignment $assignment) => $assignment->user->canSignIn() && ! in_array($assignment->user_id, $changes[$assignment->shift_id]['except'] ?? [], true))
            ->groupBy('user_id');

        $sent = [];

        foreach ($people as $assignments) {
            $person = $assignments->first()->user;
            $mine = $assignments->map(fn (Assignment $assignment) => $changes[$assignment->shift_id])
                ->sortBy(fn (array $change) => $change['shift']->starts_at->getTimestamp())->values();
            $batch = (string) Str::ulid();

            $replaced = ChangeNotice::query()->open()->where('user_id', $person->id)
                ->whereIn('shift_id', $mine->map(fn (array $change) => $change['shift']->id))
                ->orderBy('id')->lockForUpdate()->get();
            ChangeNotice::query()->whereKey($replaced->modelKeys())->update(['ended_at' => now()]);
            // The oldest open notice on each shift, whose "was" is the last time the person was asked about.
            $replaced = $replaced->unique('shift_id')->keyBy('shift_id');

            // A shift back at the time the person was last asked about (a change undone before they answered) asks nothing: the replaced notice ends and nothing new is recorded or emailed for it. A title change made with it is still told to the person, in their time email or alone.
            [$undone, $mine] = $mine->partition(function (array $change) use ($replaced) {
                $before = $replaced->get($change['shift']->id);

                return $before !== null
                    && $before->was_starts_at->equalTo($change['shift']->starts_at)
                    && $before->was_ends_at->equalTo($change['shift']->ends_at);
            });
            $mine = $mine->values();
            $timezone = Organization::current()->timezone;
            $titleChanges = $undone->filter(fn (array $change) => array_key_exists('was_title', $change) && ShiftChangedNotification::titleSentence($change['shift'], $change['was_title']) !== '')
                ->map(fn (array $change) => [
                    'shift' => $change['shift'],
                    'was' => TimeFormat::range($change['shift']->starts_at->setTimezone($timezone), $change['shift']->ends_at->setTimezone($timezone)),
                    'wasTitle' => $change['was_title'],
                ])->values();

            if ($mine->isEmpty()) {
                if ($titleChanges->isNotEmpty()) {
                    $this->titleOnly[$person->id] = ['person' => $person, 'changes' => $titleChanges->all()];
                }

                continue;
            }

            foreach ($mine as $change) {
                $before = $replaced->get($change['shift']->id);

                ChangeNotice::query()->create([
                    'batch' => $batch,
                    'user_id' => $person->id,
                    'shift_id' => $change['shift']->id,
                    'changed_by' => $actor?->id,
                    'was_starts_at' => $before?->was_starts_at ?? $change['was_starts_at'],
                    'was_ends_at' => $before?->was_ends_at ?? $change['was_ends_at'],
                ]);
            }

            ChangeLogEntry::record($actor, 'change_notice.sent', $mine->first()['shift'], [
                'batch' => $batch,
                'shift_ids' => $mine->map(fn (array $change) => $change['shift']->id)->all(),
                'count' => $mine->count(),
            ], about: $person);

            // The email's "was" is the same carried-over time the notice rows hold.
            $items = $mine->map(fn (array $change) => ['was_starts_at' => $replaced->get($change['shift']->id)?->was_starts_at ?? $change['was_starts_at'], 'was_ends_at' => $replaced->get($change['shift']->id)?->was_ends_at ?? $change['was_ends_at']] + $change);
            $sent[] = ['user' => $person, 'notification' => new ShiftTimeChangedNotification($batch, $items, $actor?->shortName())];

            if ($titleChanges->isNotEmpty()) {
                $sent[array_key_last($sent)]['notification']->withTitleChanges($titleChanges);
            }
        }

        return $sent;
    }
}
