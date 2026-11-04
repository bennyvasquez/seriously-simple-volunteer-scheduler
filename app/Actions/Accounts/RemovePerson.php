<?php

namespace App\Actions\Accounts;

use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes someone who asked to be removed (spec section 13, "People leaving"). Their shifts, regular slots and change log entries move onto the one shared "Removed person" record, their name, email address and membership ID are scrubbed from the change log, and their own row is deleted, so nothing left can tell one removed person from another. It can't be undone. Refused while they're on a shift that hasn't started or are a regular on a slot that hasn't ended; the refusals are written to be shown on screen.
 */
class RemovePerson
{
    public function handle(User $actor, User $person): void
    {
        Gate::forUser($actor)->authorize('manage', $person);

        DB::transaction(function () use ($actor, $person) {
            // Locking the row also holds back anyone putting them on a shift or a slot meanwhile: adding that row needs a shared lock on this one for its foreign key, and once this commits the row is gone, so the add fails instead of landing on the removed person.
            $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manage', $locked);

            $refusal = self::refusal($locked);

            if ($refusal !== null) {
                throw new DomainException($refusal);
            }

            $removed = User::removedPerson();

            // First, while the change log still points at this person's id, because that's how it finds their entries.
            ChangeLogEntry::forgetPersonalDetailsOf($locked);
            $this->repointIdsInsideEntries($locked, $removed);

            DB::table('assignments')->where('user_id', $locked->id)->update(['user_id' => $removed->id]);
            DB::table('slot_regulars')->where('user_id', $locked->id)->update(['user_id' => $removed->id]);
            DB::table('change_log')->where('actor_id', $locked->id)->update(['actor_id' => $removed->id]);
            DB::table('change_log')->where('affected_user_id', $locked->id)->update(['affected_user_id' => $removed->id]);
            DB::table('change_log')->where('subject_type', $locked->getMorphClass())->where('subject_id', $locked->id)->update(['subject_id' => $removed->id]);

            DB::table('sessions')->where('user_id', $locked->id)->delete();
            DB::table('password_reset_tokens')->where('email', $locked->email)->delete();
            // Login links and role assignments go with the row.
            DB::table('users')->where('id', $locked->id)->delete();

            ChangeLogEntry::record($actor, 'user.removed', $removed);
        });
    }

    /** Why this person can't be removed yet, or null when they can. */
    public static function refusal(User $person): ?string
    {
        $futureShifts = Assignment::query()
            ->where('user_id', $person->id)
            ->whereHas('shift', fn ($shift) => $shift->whereNull('cancelled_at')->where('starts_at', '>', now()))
            ->count();

        if ($futureShifts > 0) {
            return $futureShifts === 1
                ? $person->firstName().' is still on 1 future shift. Take them off that first.'
                : $person->firstName().' is still on '.$futureShifts.' future shifts. Take them off those first.';
        }

        $today = Organization::current()->today()->toDateString();
        $stillRegular = $person->regulars()->where(fn ($query) => $query->whereNull('ends_on')->orWhere('ends_on', '>=', $today))->exists();

        if ($stillRegular) {
            return $person->firstName().' is still a regular. End that first.';
        }

        return null;
    }

    /** Ids stored inside entries rather than in their columns: a person's id in `changes` (an assignment or regular's user_id), in the list of people told about a cancelled shift, and in the regulars put back on a shift a slot change brought back. Written with the query builder, the same way forgetPersonalDetailsOf() writes, because the model refuses edits. */
    private function repointIdsInsideEntries(User $person, User $removed): void
    {
        // Relies on every entry with a user_id in its changes being recorded with about: the person (PutOnShift and AddRegular do), so it's found by affected_user_id.
        $about = DB::table('change_log')->where('affected_user_id', $person->id)->whereNotNull('changes')->get(['id', 'changes']);

        foreach ($about as $row) {
            $changes = json_decode($row->changes, true);
            $touched = false;

            foreach (['old', 'new'] as $side) {
                if (isset($changes['user_id'][$side]) && (int) $changes['user_id'][$side] === $person->id) {
                    $changes['user_id'][$side] = $removed->id;
                    $touched = true;
                }
            }

            if ($touched) {
                DB::table('change_log')->where('id', $row->id)->update(['changes' => json_encode($changes)]);
            }
        }

        // Who was told about a cancelled shift (CancelShift, CreateClosure), and which regulars a slot change put back on a shift it brought back (UpdateWeeklySlot).
        foreach (['shift.cancelled' => 'people', 'shift.updated' => 'added_regular_ids'] as $action => $key) {
            $rows = DB::table('change_log')->where('action', $action)->whereJsonContains('details->'.$key, $person->id)->get(['id', 'details']);

            foreach ($rows as $row) {
                $details = json_decode($row->details, true);
                $details[$key] = array_map(fn ($id) => (int) $id === $person->id ? $removed->id : $id, $details[$key]);
                DB::table('change_log')->where('id', $row->id)->update(['details' => json_encode($details)]);
            }
        }
    }
}
