<?php

namespace App\Actions\Scheduling;

use App\Calendar\TimeFormat;
use App\ChangeLog\Describe;
use App\Cover\CoverFill;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\NoAnswerLeadNotification;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * The 48-hour heads-up (spec section 10, decision 6): a shift starts within HOURS and someone on it still hasn't answered its time change. The area's active leads who can sign in get one email naming those people, once per shift; with no such lead, every active coordinator who can sign in gets it instead (decision 13). Only notices made more than HOURS before the shift count, so a change made inside the window sends nothing (decision 12). Notices of people no longer on the shift, and shifts that started or are cancelled, are skipped. Each shift is handled in its own transaction: `lead_told_at` is set on its notices and one `change_notice.lead_told` entry is logged as the system (`details` hold the shift id and the count; names stay out). The emails go out after the commit. run() returns the number of shifts told about.
 */
class SendChangeHeadsUps
{
    public const HOURS = 48;

    public function run(): int
    {
        $now = CarbonImmutable::now('UTC');

        // Found without locks; each shift is read again under its locks below.
        $candidates = Shift::query()
            ->whereNull('cancelled_at')
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addHours(self::HOURS))
            ->whereIn('id', ChangeNotice::query()->open()->whereNull('lead_told_at')->select('shift_id'))
            ->orderBy('starts_at')->orderBy('id')
            ->get(['id', 'area_id']);

        $told = 0;

        foreach ($candidates as $candidate) {
            $send = DB::transaction(fn () => $this->tellLocked($candidate));

            if ($send === null) {
                continue;
            }

            $told++;

            try {
                $send();
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $told;
    }

    /** @return ?Closure The closure that sends the emails after the commit, or null when there's nothing to say about the shift. */
    private function tellLocked(Shift $candidate): ?Closure
    {
        $now = CarbonImmutable::now('UTC');

        // Locks in the project's order: area, shift, then the assignments and the notices.
        Area::query()->whereKey($candidate->area_id)->lockForUpdate()->first();
        $shift = Shift::query()->with('area')->whereKey($candidate->id)->lockForUpdate()->first();

        if ($shift === null || $shift->cancelled_at !== null || $shift->starts_at->lessThanOrEqualTo($now) || $shift->starts_at->greaterThan($now->addHours(self::HOURS))) {
            return null;
        }

        $cutoff = $shift->starts_at->subHours(self::HOURS);
        $stillOn = DB::table('assignments')->where('shift_id', $shift->id)->orderBy('id')->lockForUpdate()->pluck('user_id');
        $notices = ChangeNotice::query()->with('user')->open()->whereNull('lead_told_at')->where('shift_id', $shift->id)
            ->whereIn('user_id', $stillOn)->orderBy('id')->lockForUpdate()->get()
            ->filter(fn (ChangeNotice $notice) => $notice->created_at->lessThan($cutoff))->values();

        if ($notices->isEmpty()) {
            return null;
        }

        ChangeNotice::query()->whereKey($notices->modelKeys())->update(['lead_told_at' => $now]);

        ChangeLogEntry::record(null, 'change_notice.lead_told', $shift, [
            'shift_id' => $shift->id,
            'count' => $notices->count(),
        ]);

        $timezone = Organization::current()->timezone;
        $first = $notices->first();
        $names = $notices->map(fn (ChangeNotice $notice) => $notice->user->shortName())->sort()->values()->all();
        $was = TimeFormat::range($first->was_starts_at->setTimezone($timezone), $first->was_ends_at->setTimezone($timezone));
        $sends = [];

        foreach ($this->audience($shift->area) as [$person, $reason]) {
            $sends[] = [$person, new NoAnswerLeadNotification($shift, $names, $was, $reason)];
        }

        return CoverFill::sender($sends);
    }

    /**
     * The area's active leads who can sign in, each with the reason their email gives, or every active coordinator who can sign in when the area has none.
     *
     * @return list<array{0: User, 1: string}>
     */
    private function audience(Area $area): array
    {
        $leads = User::query()->people()->whereNull('deactivated_at')
            ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::AreaLead->value)->where('area_id', $area->id))
            ->orderBy('id')->get()->filter(fn (User $person) => $person->canSignIn());

        if ($leads->isNotEmpty()) {
            $reason = Describe::roleName(Role::AreaLead, $area);

            return $leads->map(fn (User $person) => [$person, $reason])->values()->all();
        }

        return User::query()->people()->whereNull('deactivated_at')
            ->whereHas('roles', fn (Builder $query) => $query->where('role', Role::Coordinator->value))
            ->orderBy('id')->get()->filter(fn (User $person) => $person->canSignIn())
            ->map(fn (User $person) => [$person, Describe::roleName(Role::Coordinator)])->values()->all();
    }
}
