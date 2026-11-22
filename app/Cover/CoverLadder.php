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
 * The escalation ladder for cover requests (spec section 8.3), with the default timings. A shift still open RESEND_HOURS before it starts gets its cover request sent again, once per shift, counting only requests made before that window (decision 11). A shift still open URGENT_HOURS before it starts is escalated, once: the area's active leads and everyone with the coordinator role get the urgent email (decision 12), and when nobody but shadows is left on it, the shadows are told not to come in if nobody takes it (decision 6). Cancelled shifts are skipped and picked up again if they're brought back. Only a shift's oldest openPlaces() open requests count (openRequestsLocked()), so a request left open by a path that put someone on the shift without filling it is never sent again or escalated. run() is the scheduled step; escalateLocked() is shared with releasing a shift inside URGENT_HOURS.
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

        $requests = self::openRequestsLocked($shift);

        if ($requests->isEmpty()) {
            return [null, null];
        }

        if ($shift->starts_at->lessThanOrEqualTo(now()->addHours(self::URGENT_HOURS))) {
            $unescalated = $requests->whereNull('escalated_at')->values();

            if ($unescalated->isEmpty()) {
                return [null, null];
            }

            $sends = $this->escalateLocked($shift, $unescalated);

            return ['escalated', CoverFill::sender($sends)];
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
     * The shift's open cover requests that stand for one of its open places: the oldest openPlaces() of them, oldest first, with their releasers, locked. Every place a request stood for should have filled it, so any beyond that are left alone rather than sent again or escalated. The caller holds the shift lock.
     *
     * @return Collection<int, CoverRequest>
     */
    public static function openRequestsLocked(Shift $lockedShift): Collection
    {
        $requests = CoverRequest::query()->with('releasedBy')->open()->where('shift_id', $lockedShift->id)->orderBy('created_at')->orderBy('id')->lockForUpdate()->get();

        return $requests->take($lockedShift->openPlaces())->values();
    }

    /**
     * Marks the requests escalated, logs cover_request.escalated for each (with the shift as the subject), and returns what to send, without sending: the urgent email for each lead and coordinator and, when nobody but shadows is left on the shift, the shadows' "still nobody to shadow" email. Each person appears at most once for the shift, even when they're both a lead and a coordinator. The caller holds the area, shift and cover request locks and sends after the commit; ReleaseShifts merges the emails of several shifts per person first. `$openRequests` are in the order they were made; the oldest names the releaser. `$actor` is null when the ladder runs it, and the person releasing when ReleaseShifts escalates at release; that person is left out of the urgent email, so a lead or coordinator releasing inside URGENT_HOURS isn't told about their own release.
     *
     * @param  Collection<int, CoverRequest>  $openRequests
     * @return list<array{0: User, 1: CoverUrgentNotification|ShadowSpotNotification}>
     */
    public function escalateLocked(Shift $lockedShift, Collection $openRequests, ?User $actor = null): array
    {
        $lockedShift->loadMissing('area');
        $area = $lockedShift->area;
        $oldest = $openRequests->first();
        $oldest->loadMissing('releasedBy');
        $releaser = $oldest->releaserName();

        foreach ($openRequests as $request) {
            $request->escalated_at = now();
            $changes = ChangeLogEntry::changesOf($request);
            $request->save();

            ChangeLogEntry::record($actor, 'cover_request.escalated', $lockedShift, ['cover_request_id' => $request->id], $changes);
        }

        $sends = [];
        $audience = self::urgentAudience($area);

        if ($actor !== null) {
            unset($audience[$actor->id]);
        }

        foreach ($audience as $id => [$person, $reason]) {
            $sends[$id] = [$person, new CoverUrgentNotification($lockedShift, $releaser, $oldest->created_at, $oldest->notified_count, $oldest->resent_at !== null, $reason)];
        }

        if ($lockedShift->filledCount() === 0) {
            // Leads come first in the audience, in id order, so the first with the lead reason is the first lead.
            $leadReason = Describe::roleName(Role::AreaLead, $area);
            $lead = collect($audience)->first(fn (array $entry) => $entry[1] === $leadReason);
            $leadText = $lead === null ? null : $lead[0]->firstName().' ('.$area->leadTitle().')';
            $shadows = Assignment::query()->with('user')->where('shift_id', $lockedShift->id)->where('type', AssignmentType::Shadow)->orderBy('id')->get();

            foreach ($shadows as $shadow) {
                $sends[$shadow->user_id] ??= [$shadow->user, new ShadowSpotNotification($lockedShift, ShadowSpotNotice::StillUncovered, $releaser, leadText: $leadText)];
            }
        }

        return array_values($sends);
    }

    /**
     * Who gets the urgent email for a shift in this area (decision 12): its active leads, then everyone active with the coordinator role, each once and keyed by id, with the reason their email gives ("a shift lead for Front desk" or "a coordinator"). ReleaseShifts leaves these people out of a cover request for a shift it escalates, so they get only the urgent email.
     *
     * @return array<int, array{0: User, 1: string}>
     */
    public static function urgentAudience(Area $area): array
    {
        $leads = User::query()->people()->whereNull('deactivated_at')
            ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::AreaLead->value)->where('area_id', $area->id))
            ->orderBy('id')->get();
        $coordinators = User::query()->people()->whereNull('deactivated_at')
            ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::Coordinator->value))
            ->orderBy('id')->get();

        $audience = [];
        $leadReason = Describe::roleName(Role::AreaLead, $area);

        foreach ($leads as $lead) {
            $audience[$lead->id] = [$lead, $leadReason];
        }

        foreach ($coordinators as $coordinator) {
            $audience[$coordinator->id] ??= [$coordinator, 'a coordinator'];
        }

        return $audience;
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
        $notification = new CoverRequestNotification(collect([$lockedShift]), $oldest->releaserName(), $oldest->note, stillOpen: true);

        return CoverFill::sender($recipients->map(fn (User $person) => [$person, $notification])->values()->all());
    }
}
