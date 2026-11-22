<?php

namespace App\Policies;

use App\Models\Shift;
use App\Models\User;

class ShiftPolicy
{
    public function update(User $user, Shift $shift): bool
    {
        return $user->canManageArea($shift->area);
    }

    public function assign(User $user, Shift $shift): bool
    {
        return $user->canManageArea($shift->area);
    }

    public function removePerson(User $user, Shift $shift): bool
    {
        return $user->canManageArea($shift->area);
    }

    public function cancel(User $user, Shift $shift): bool
    {
        return $user->canManageArea($shift->area);
    }

    public function restore(User $user, Shift $shift): bool
    {
        return $user->canManageArea($shift->area);
    }

    /** A volunteer releasing their own place on a shift ("Can't make it"). ReleaseShifts checks the shift itself (cancelled, started) under its locks. */
    public function release(User $user, Shift $shift): bool
    {
        return $user->isActive() && $shift->assignments()->where('user_id', $user->id)->exists();
    }
}
