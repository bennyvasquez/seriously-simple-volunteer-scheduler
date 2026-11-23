<?php

namespace App\Cover;

use App\Enums\AssignmentType;
use App\Models\Assignment;
use App\Models\User;

/**
 * How far someone has got with shadowing (spec section 9): a shadow shift counts once it has ended, unless it was cancelled or the person was marked a no-show. A shadow place someone left is gone, so it never counts.
 */
final class ShadowProgress
{
    /** The person's shadow shifts that count so far. */
    public static function done(User $person): int
    {
        return Assignment::query()
            ->where('user_id', $person->id)
            ->where('type', AssignmentType::Shadow)
            ->whereNull('no_show_at')
            ->whereHas('shift', fn ($shifts) => $shifts->active()->where('ends_at', '<=', now()))
            ->count();
    }
}
