<?php

namespace App\Actions\Scheduling;

use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * "Still works for me" (spec section 10): the person says the new time works. It confirms every open notice of the batch whose shift hasn't started and isn't cancelled and that the person is still on, in one transaction, and logs one `change_notice.confirmed` entry for them (the earliest shift is its subject; `details` hold the batch and the count). Notices already confirmed, replaced by a newer change or ended by leaving the shift are left alone, so confirming twice confirms once and an old email confirms nothing. Returns how many it confirmed; with none it logs nothing.
 */
class ConfirmChangeNotice
{
    public function handle(User $person, string $batch): int
    {
        return DB::transaction(function () use ($person, $batch) {
            $notices = ChangeNotice::query()->with('shift')->open()
                ->where('change_notices.batch', $batch)->where('change_notices.user_id', $person->id)
                ->join('shifts', 'shifts.id', '=', 'change_notices.shift_id')
                ->whereNull('shifts.cancelled_at')->where('shifts.starts_at', '>', now())
                ->orderBy('shifts.starts_at')->orderBy('change_notices.id')
                ->select('change_notices.*')->lockForUpdate()->get();

            $onIt = Assignment::query()->where('user_id', $person->id)->whereIn('shift_id', $notices->pluck('shift_id'))->pluck('shift_id');
            $notices = $notices->filter(fn (ChangeNotice $notice) => $onIt->contains($notice->shift_id))->values();

            if ($notices->isEmpty()) {
                return 0;
            }

            ChangeNotice::query()->whereKey($notices->modelKeys())->update(['confirmed_at' => now()]);

            ChangeLogEntry::record($person, 'change_notice.confirmed', $notices->first()->shift, [
                'batch' => $batch,
                'shift_ids' => $notices->pluck('shift_id')->all(),
                'count' => $notices->count(),
            ], about: $person);

            return $notices->count();
        });
    }
}
