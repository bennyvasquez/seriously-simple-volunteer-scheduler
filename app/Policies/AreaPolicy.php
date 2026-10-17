<?php

namespace App\Policies;

use App\Models\Area;
use App\Models\User;

class AreaPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->canViewCalendar();
    }

    public function create(User $user): bool
    {
        return $user->isActive() && $user->isCoordinator();
    }

    public function update(User $user, Area $area): bool
    {
        return $user->isActive() && $user->isCoordinator();
    }

    public function manageSchedule(User $user, Area $area): bool
    {
        return $user->canManageArea($area);
    }

    public function close(User $user, Area $area): bool
    {
        return $user->isActive() && $user->isCoordinator();
    }
}
