<?php

namespace App\Cover;

use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\ShadowSpotNotice;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\CoverRequest;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShadowSpotNotification;
use App\Notifications\ShiftCoveredNotification;
use App\Notifications\ShiftTakenLeadNotification;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Notifications\Notification;
use Throwable;

/**
 * Filling a cover request when someone takes a released place, shared by TakeShift and PutOnShift so a coordinator filling a released shift stops the ladder and tells the releaser too. A coordinator's change that puts regulars on shifts (AddRegular, RestoreShift bringing a shift back, UpdateWeeklySlot bringing a date back) fills them through fillForRegularsLocked(). With several open requests on a shift, the oldest is filled first (decision 9), so the ladder's next run names the right releaser.
 */
final class CoverFill
{
    /**
     * Fills the shift's oldest open cover request through fillOldestLocked() and returns a closure that sends, after the commit, the notices() for it, or null when the shift has no open request. The caller holds the area (when it takes one) and shift locks. `$filled` receives the request filled, so the caller can name the releaser and show the note.
     */
    public static function fillLocked(?User $actor, Shift $lockedShift, User $person, bool $nobodyButShadowsBefore, ?string $regularHow = null, ?CoverRequest &$filled = null): ?Closure
    {
        $request = self::fillOldestLocked($actor, $lockedShift, $person);

        if ($request === null) {
            return null;
        }

        $filled = $request;

        return self::sender(self::notices($actor, $lockedShift, $person, $nobodyButShadowsBefore, $request->releasedBy, $regularHow));
    }

    /**
     * Fills the shift's oldest open cover request (`filled_by`, `filled_at`) and logs `cover_request.filled` with the shift as the subject, about the person who took the place, with only `filled_by` and `filled_at` in its changes (RemovePerson::repointIdsInsideEntries() finds it by the person). Sends nothing. Returns the request with its releaser loaded, or null when the shift has no open request. The request is locked here, after the shift the caller holds. TakeShift calls this for each shift a volunteer is put on when they take a slot, and merges the notices.
     */
    public static function fillOldestLocked(?User $actor, Shift $lockedShift, User $person): ?CoverRequest
    {
        $request = CoverRequest::query()->with('releasedBy')->open()->where('shift_id', $lockedShift->id)
            ->orderBy('created_at')->orderBy('id')->lockForUpdate()->first();

        if ($request === null) {
            return null;
        }

        $request->filled_by = $person->id;
        $request->filled_at = now();
        $changes = ChangeLogEntry::changesOf($request);
        $request->save();

        ChangeLogEntry::record($actor, 'cover_request.filled', $lockedShift, ['cover_request_id' => $request->id], $changes, about: $person);

        return $request;
    }

    /**
     * Fills the shift's oldest open cover requests for the regulars a coordinator's change just put on it, one request per person in the order given, while there are open ones, through fillOldestLocked() (so each `cover_request.filled` entry is about the new regular). Returns the notices() for them with the leads left out, since a coordinator did it, for the caller to merge with its other shifts and send after the commit with sendAfterCommit(). `$nobodyButShadowsBefore` is whether nobody but shadows was on the shift before these people joined; only the first person's notices tell the shadows, so they hear "back on" once. The caller holds the shift lock (and every earlier lock); the requests are locked here, after it.
     *
     * @param  iterable<User>  $people
     * @return list<array{0: User, 1: Notification}>
     */
    public static function fillForRegularsLocked(?User $actor, Shift $lockedShift, iterable $people, bool $nobodyButShadowsBefore): array
    {
        $sends = [];
        $first = true;

        foreach ($people as $person) {
            $request = self::fillOldestLocked($actor, $lockedShift, $person);

            if ($request === null) {
                break;
            }

            array_push($sends, ...self::notices($actor, $lockedShift, $person, $nobodyButShadowsBefore && $first, $request->releasedBy, tellLeads: false));
            $first = false;
        }

        return $sends;
    }

    /**
     * Sends these emails after the commit and the response, merged first so each person gets one email of a kind.
     *
     * @param  list<array{0: User, 1: Notification}>  $sends
     */
    public static function sendAfterCommit(array $sends): void
    {
        if ($sends === []) {
            return;
        }

        defer(self::sender(self::merged($sends)));
    }

    /**
     * Who hears that `$person` took a place on the shift, apart from the person themselves, each once: the releaser gets "{name} is covering your {date} shift" (unless they took it back), the shadows get "back on" when a cover request was filled and nobody but shadows was on the shift before (decision 6; shadows on an open place that was never released were never told it was on hold), and the area's active leads get the lead notice (decision 18), unless `$tellLeads` is false or someone else (a coordinator or lead) put the person on, since they did it. `$releaser` is null for an open place that isn't a cover request.
     *
     * @return list<array{0: User, 1: Notification}>
     */
    public static function notices(?User $actor, Shift $lockedShift, User $person, bool $nobodyButShadowsBefore, ?User $releaser, ?string $regularHow = null, bool $tellLeads = true): array
    {
        $lockedShift->loadMissing('area');
        $taker = $person->firstName();
        $sends = [];

        if ($releaser !== null && $releaser->id !== $person->id) {
            $sends[$releaser->id] = [$releaser, new ShiftCoveredNotification($lockedShift, $taker)];
        }

        if ($nobodyButShadowsBefore && $releaser !== null) {
            foreach (self::shadows($lockedShift) as $shadow) {
                $sends[$shadow->id] ??= [$shadow, new ShadowSpotNotification($lockedShift, ShadowSpotNotice::BackOn, $releaser?->firstName() ?? '', takerFirstName: $taker)];
            }
        }

        if ($tellLeads && ($actor === null || $actor->id === $person->id)) {
            $leads = User::query()->people()->whereNull('deactivated_at')
                ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::AreaLead->value)->where('area_id', $lockedShift->area_id))
                ->orderBy('id')->get();

            foreach ($leads as $lead) {
                $sends[$lead->id] ??= [$lead, new ShiftTakenLeadNotification($lockedShift, $taker, $releaser?->firstName(), $releaser?->id === $person->id, $regularHow)];
            }
        }

        // The person gets the caller's own email instead.
        unset($sends[$person->id]);

        return array_values($sends);
    }

    /**
     * Folds emails of the same kind to the same person into one, using the notification's merge(): the releaser's "covering" or a shadow's "back on" for several dates of a slot someone took. Kept in the order first sent.
     *
     * @param  list<array{0: User, 1: Notification}>  $sends
     * @return list<array{0: User, 1: Notification}>
     */
    public static function merged(array $sends): array
    {
        $merged = [];

        foreach ($sends as [$recipient, $notification]) {
            $key = $recipient->id.' '.$notification::class.($notification instanceof ShadowSpotNotification ? ' '.$notification->notice->value : '');

            if (! isset($merged[$key])) {
                $merged[$key] = [$recipient, $notification];
            } elseif (method_exists($merged[$key][1], 'merge')) {
                $merged[$key][1]->merge($notification);
            } else {
                // A kind that can't be merged is sent as it is rather than dropped.
                $merged[$key.' '.count($merged)] = [$recipient, $notification];
            }
        }

        return array_values($merged);
    }

    /**
     * A closure that sends each email through sendEach(), for after the commit.
     *
     * @param  list<array{0: User, 1: Notification}>  $sends
     */
    public static function sender(array $sends): Closure
    {
        return fn () => self::sendEach($sends);
    }

    /**
     * Sends each email in turn. One that fails (a bad address, the mail server refusing it) is reported and doesn't stop the ones after it. Every cover email goes through here: releasing, taking, filling and the ladder.
     *
     * @param  iterable<array{0: User, 1: Notification}>  $sends
     */
    public static function sendEach(iterable $sends): void
    {
        foreach ($sends as [$recipient, $notification]) {
            try {
                $recipient->notify($notification);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    /** @return list<User> The shift's shadows, in the order they were put on. */
    public static function shadows(Shift $shift): array
    {
        return Assignment::query()->with('user')->where('shift_id', $shift->id)->where('type', AssignmentType::Shadow)
            ->orderBy('id')->get()->pluck('user')->all();
    }
}
