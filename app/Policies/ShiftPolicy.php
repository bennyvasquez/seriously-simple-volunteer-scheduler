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

    public function cancel(User $user, Shift $shift): bool
    {
        return $user->canManageArea($shift->area);
    }
}
