<?php

namespace App\Actions\Scheduling;

use App\Calendar\PersonSearch;
use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\Shift;
use App\Models\User;

/**
 * The checks for editing a shift that has people on it. Run on a shift whose new values are filled in but not saved, inside a transaction that already holds the area and shift locks, so the people and their other shifts are read after the locks. Only what changed is checked: lowering the shadow spots below the shadows on the shift, and a new time that overlaps another active shift of someone on it. Lowering the people needed below the people on the shift is allowed, since it's a target and not a limit.
 */
class StaffedShiftChecks
{
    /** @return array{field: string, message: string}|null The first problem, worded to show beside the field, or null. */
    public static function problem(User $viewer, Shift $lockedShift): ?array
    {
        $assignments = Assignment::query()->with('user')->where('shift_id', $lockedShift->id)->orderBy('id')->lockForUpdate()->get();

        if ($lockedShift->isDirty('shadow_spots')) {
            $shadows = $assignments->where('type', AssignmentType::Shadow)->count();

            if ($shadows > $lockedShift->shadow_spots) {
                $who = $shadows === 1 ? '1 person is shadowing' : $shadows.' people are shadowing';

                return ['field' => 'shadow_spots', 'message' => $who.' this shift. Remove someone from it before lowering it to '.$lockedShift->shadow_spots.'.'];
            }
        }

        if ($lockedShift->isDirty(['starts_at', 'ends_at'])) {
            foreach ($assignments as $assignment) {
                $other = PutOnShift::overlappingShift($assignment->user, $lockedShift);

                if ($other !== null) {
                    return ['field' => 'start_time', 'message' => PersonSearch::displayName($viewer, $assignment->user).' is on '.$other->area->name.', '.$other->whenText().' at that time.'];
                }
            }
        }

        return null;
    }
}
