<?php

namespace App\Actions\Scheduling;

use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A lead (in their area) or a coordinator marks a shadow as a no-show once the shift has ended, or undoes it. A no-show shadow shift doesn't count toward review (spec section 9). Only shadows for now; regular places wait for volunteer hours. Refused on a shift that hasn't ended or is cancelled. Marking what is already marked changes and logs nothing. Logs assignment.no_show with no_show_at old and new, about the person, with the shift as the subject. The refusals are written to be shown on screen.
 */
class MarkNoShow
{
    public function handle(User $actor, Assignment $assignment, bool $noShow): void
    {
        $shift = $assignment->shift;

        Gate::forUser($actor)->authorize('update', $shift);

        DB::transaction(function () use ($actor, $assignment, $shift, $noShow) {
            Area::query()->whereKey($shift->area_id)->lockForUpdate()->firstOrFail();
            $locked = Shift::query()->whereKey($shift->id)->lockForUpdate()->firstOrFail();
            $current = Assignment::query()->with('user')->where('shift_id', $locked->id)->whereKey($assignment->id)->lockForUpdate()->first();

            if ($current === null) {
                throw new DomainException('They aren\'t on this shift any more.');
            }

            if ($current->type !== AssignmentType::Shadow) {
                throw new DomainException('No-shows are only marked for shadows for now.');
            }

            if ($locked->cancelled_at !== null) {
                throw new DomainException('You can\'t mark a no-show on a cancelled shift.');
            }

            if ($locked->ends_at->greaterThan(now())) {
                throw new DomainException('You can mark a no-show once the shift has ended.');
            }

            $current->no_show_at = $noShow ? ($current->no_show_at ?? now()) : null;
            $changes = ChangeLogEntry::changesOf($current);

            if ($changes === []) {
                return;
            }

            $current->save();
            ChangeLogEntry::record($actor, 'assignment.no_show', $locked, ['assignment_id' => $current->id], $changes, about: $current->user);
        });
    }
}
