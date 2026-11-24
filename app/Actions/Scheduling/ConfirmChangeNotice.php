<?php

namespace App\Actions\Scheduling;

use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Shift;
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
            // Locks in the project's order: the batch's shifts (by start, then id), then the person's assignments on them, then the notices. This changes no area or weekly slot, so it takes neither lock.
            $shiftIds = ChangeNotice::query()->where('batch', $batch)->where('user_id', $person->id)->pluck('shift_id');
            $shifts = Shift::query()->whereKey($shiftIds)->orderBy('starts_at')->orderBy('id')->lockForUpdate()->get()
                ->filter(fn (Shift $shift) => $shift->cancelled_at === null && $shift->starts_at->greaterThan(now()))->keyBy('id');
            $onIt = Assignment::query()->where('user_id', $person->id)->whereIn('shift_id', $shifts->keys())->orderBy('id')->lockForUpdate()->pluck('shift_id');
            $notices = ChangeNotice::query()->open()->where('change_notices.batch', $batch)->where('change_notices.user_id', $person->id)
                ->whereIn('shift_id', $onIt)->lockForUpdate()->get()
                ->each(fn (ChangeNotice $notice) => $notice->setRelation('shift', $shifts[$notice->shift_id]))
                ->sortBy(fn (ChangeNotice $notice) => [$notice->shift->starts_at->getTimestamp(), $notice->id])->values();

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
