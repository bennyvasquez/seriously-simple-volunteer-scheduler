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

/**
 * Filling a cover request when someone takes a released place, shared by TakeShift and PutOnShift so a coordinator filling a released shift stops the ladder and tells the releaser too. With several open requests on a shift, the oldest is filled first (decision 9), so the ladder's next run names the right releaser.
 */
final class CoverFill
{
    /**
     * Fills the shift's oldest open cover request (`filled_by`, `filled_at`) and logs `cover_request.filled` with the shift as the subject, about the person who took the place, with only `filled_by` and `filled_at` in its changes (RemovePerson::repointIdsInsideEntries() finds it by the person). Returns a closure that sends, after the commit, the notices() for it, or null when the shift has no open request. The caller holds the area (when it takes one) and shift locks; the request is locked here, after the shift. `$filled` receives the request filled, so the caller can name the releaser and show the note.
     */
    public static function fillLocked(?User $actor, Shift $lockedShift, User $person, bool $nobodyButShadowsBefore, ?string $regularHow = null, ?CoverRequest &$filled = null): ?Closure
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

        $filled = $request;
        $sends = self::notices($actor, $lockedShift, $person, $nobodyButShadowsBefore, $request->releasedBy, $regularHow);

        return function () use ($sends) {
            foreach ($sends as [$recipient, $notification]) {
                $recipient->notify($notification);
            }
        };
    }

    /**
     * Who hears that `$person` took a place on the shift, apart from the person themselves, each once: the releaser gets "{name} is covering your {date} shift" (unless they took it back), the shadows get "back on" when nobody but shadows was on it before (decision 6), and the area's active leads get the lead notice (decision 18), except when someone else (a coordinator or lead) put the person on, since they did it. `$releaser` is null for an open place that isn't a cover request.
     *
     * @return list<array{0: User, 1: Notification}>
     */
    public static function notices(?User $actor, Shift $lockedShift, User $person, bool $nobodyButShadowsBefore, ?User $releaser, ?string $regularHow = null): array
    {
        $lockedShift->loadMissing('area');
        $taker = $person->firstName();
        $sends = [];

        if ($releaser !== null && $releaser->id !== $person->id) {
            $sends[$releaser->id] = [$releaser, new ShiftCoveredNotification($lockedShift, $taker)];
        }

        if ($nobodyButShadowsBefore) {
            foreach (self::shadows($lockedShift) as $shadow) {
                $sends[$shadow->id] ??= [$shadow, new ShadowSpotNotification($lockedShift, ShadowSpotNotice::BackOn, $releaser?->firstName() ?? '', takerFirstName: $taker)];
            }
        }

        if ($actor === null || $actor->id === $person->id) {
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

    /** @return list<User> The shift's shadows, in the order they were put on. */
    public static function shadows(Shift $shift): array
    {
        return Assignment::query()->with('user')->where('shift_id', $shift->id)->where('type', AssignmentType::Shadow)
            ->orderBy('id')->get()->pluck('user')->all();
    }
}
