<?php

namespace App\Cover;

use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\ShadowSpotNotice;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\CoverRequest;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\CoverRequestNotification;
use App\Notifications\CoverUrgentNotification;
use App\Notifications\ShadowSpotNotification;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The escalation ladder for cover requests (spec section 8.3), with the default timings. A shift still open RESEND_HOURS before it starts gets its cover request sent again, once per shift, counting only requests made before that window (decision 11). A shift still open URGENT_HOURS before it starts is escalated, once: the area's active leads and everyone with the coordinator role get the urgent email (decision 12), and when nobody but shadows is left on it, the shadows are told not to come in if nobody takes it (decision 6). Cancelled shifts are skipped and picked up again if they're brought back. run() is the scheduled step; escalateLocked() is shared with releasing a shift inside URGENT_HOURS.
 */
final class CoverLadder
{
    public const RESEND_HOURS = 72;

    public const URGENT_HOURS = 24;

    /** @return array{resent: int, escalated: int} How many shifts were sent again and how many were escalated. */
    public function run(): array
    {
        $counts = ['resent' => 0, 'escalated' => 0];

        // Found without locks; each shift is read again under its locks below.
        $candidates = Shift::query()
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', now())
            ->where('starts_at', '<=', now()->addHours(self::RESEND_HOURS))
            ->whereIn('id', CoverRequest::query()->open()->select('shift_id'))
            ->orderBy('starts_at')->orderBy('id')
            ->get(['id', 'area_id']);

        foreach ($candidates as $candidate) {
            [$step, $send] = DB::transaction(fn () => $this->step($candidate));

            if ($step === null) {
                continue;
            }

            $counts[$step]++;

            // After the commit. A scheduled run has no response to wait for, so it sends here; one failed send is reported and doesn't stop the other shifts.
            try {
                $send();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $counts;
    }

    /** @return array{0: ?string, 1: ?Closure} The step taken ("resent" or "escalated") and the closure that sends its emails, or nulls when there's nothing to do. */
    private function step(Shift $candidate): array
    {
        Area::query()->whereKey($candidate->area_id)->lockForUpdate()->first();
        $shift = Shift::query()->with('area')->whereKey($candidate->id)->lockForUpdate()->first();

        if ($shift === null || $shift->cancelled_at !== null || $shift->starts_at->lessThanOrEqualTo(now()) || $shift->openPlaces() === 0) {
            return [null, null];
        }

        $requests = CoverRequest::query()->with('releasedBy')->open()->where('shift_id', $shift->id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

        if ($requests->isEmpty()) {
            return [null, null];
        }

        if ($shift->starts_at->lessThanOrEqualTo(now()->addHours(self::URGENT_HOURS))) {
            $unescalated = $requests->whereNull('escalated_at')->values();

            return $unescalated->isEmpty() ? [null, null] : ['escalated', $this->escalateLocked($shift, $unescalated)];
        }

        // Once per shift: a shift whose request already went out again never gets a second "Still open", and a request made inside the window doesn't count, since its first email went out inside it (decision 11).
        if ($requests->contains(fn (CoverRequest $request) => $request->resent_at !== null)) {
            return [null, null];
        }

        $cutoff = $shift->starts_at->subHours(self::RESEND_HOURS);
        $releasedEarlyEnough = $requests->filter(fn (CoverRequest $request) => $request->created_at->lessThan($cutoff))->values();

        if ($releasedEarlyEnough->isEmpty()) {
            return [null, null];
        }

        return ['resent', $this->resendLocked($shift, $releasedEarlyEnough->first(), $releasedEarlyEnough)];
    }

    /**
     * Marks the requests escalated, logs cover_request.escalated for each (with the shift as the subject), and returns a closure that sends the urgent emails and, when nobody but shadows is left on the shift, the shadows' "still nobody to shadow" email. Each person gets at most one email, even when they're both a lead and a coordinator. The caller holds the area, shift and cover request locks, and runs the closure after the commit. `$openRequests` are in the order they were made; the oldest names the releaser. `$actor` is null when the ladder runs it.
     *
     * @param  Collection<int, CoverRequest>  $openRequests
     */
    public function escalateLocked(Shift $lockedShift, Collection $openRequests, ?User $actor = null): Closure
    {
        $lockedShift->loadMissing('area');
        $area = $lockedShift->area;
        $oldest = $openRequests->first();
        $oldest->loadMissing('releasedBy');
        $releaser = $oldest->releasedBy->firstName();

        foreach ($openRequests as $request) {
            $request->escalated_at = now();
            $changes = ChangeLogEntry::changesOf($request);
            $request->save();

            ChangeLogEntry::record($actor, 'cover_request.escalated', $lockedShift, ['cover_request_id' => $request->id], $changes);
        }

        $leads = User::query()->people()->whereNull('deactivated_at')
            ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::AreaLead->value)->where('area_id', $area->id))
            ->orderBy('id')->get();
        $coordinators = User::query()->people()->whereNull('deactivated_at')
            ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::Coordinator->value))
            ->orderBy('id')->get();

        $sends = [];
        $leadReason = Describe::roleName(Role::AreaLead, $area);

        foreach ($leads as $lead) {
            $sends[$lead->id] = [$lead, new CoverUrgentNotification($lockedShift, $releaser, $oldest->created_at, $oldest->notified_count, $oldest->resent_at !== null, $leadReason)];
        }

        foreach ($coordinators as $coordinator) {
            $sends[$coordinator->id] ??= [$coordinator, new CoverUrgentNotification($lockedShift, $releaser, $oldest->created_at, $oldest->notified_count, $oldest->resent_at !== null, 'a coordinator')];
        }

        if ($lockedShift->filledCount() === 0) {
            $leadText = $leads->isEmpty() ? null : $leads->first()->firstName().' ('.$area->leadTitle().')';
            $shadows = Assignment::query()->with('user')->where('shift_id', $lockedShift->id)->where('type', AssignmentType::Shadow)->orderBy('id')->get();

            foreach ($shadows as $shadow) {
                $sends[$shadow->user_id] ??= [$shadow->user, new ShadowSpotNotification($lockedShift, ShadowSpotNotice::StillUncovered, $releaser, leadText: $leadText)];
            }
        }

        return function () use ($sends) {
            foreach ($sends as [$person, $notification]) {
                $person->notify($notification);
            }
        };
    }

    /**
     * Marks the requests sent again (the ones made before the window), logs cover_request.resent for each, and returns a closure that sends "Still open" to everyone who could take the shift now, once each, naming the oldest request's releaser and note.
     *
     * @param  Collection<int, CoverRequest>  $unsent
     */
    private function resendLocked(Shift $lockedShift, CoverRequest $oldest, Collection $unsent): Closure
    {
        foreach ($unsent as $request) {
            $request->resent_at = now();
            $changes = ChangeLogEntry::changesOf($request);
            $request->save();

            ChangeLogEntry::record(null, 'cover_request.resent', $lockedShift, ['cover_request_id' => $request->id], $changes);
        }

        $recipients = CoverEligibility::recipients($lockedShift);
        $notification = new CoverRequestNotification(collect([$lockedShift]), $oldest->releasedBy->firstName(), $oldest->note, stillOpen: true);

        return function () use ($recipients, $notification) {
            foreach ($recipients as $person) {
                $person->notify($notification);
            }
        };
    }
}
