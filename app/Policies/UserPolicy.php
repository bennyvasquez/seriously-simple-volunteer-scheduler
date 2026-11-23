<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\User;

class UserPolicy
{
    /** Anyone active with a role can open the people list; what they see depends on the role. */
    public function viewAny(User $viewer): bool
    {
        return $viewer->canViewCalendar();
    }

    public function view(User $viewer, User $person): bool
    {
        return $viewer->canSee($person);
    }

    public function invite(User $viewer): bool
    {
        return $viewer->isActive() && $viewer->isCoordinator();
    }

    /** Coordinators and admins clear people for regular shifts (spec section 9). Coordinators hold a role, so they're already cleared and never clear themselves. */
    public function clear(User $viewer, User $person): bool
    {
        return $viewer->isActive() && $viewer->isCoordinator() && ! $person->is_removed_person;
    }

    /** Coordinators and admins manage people, but only admins manage an admin, nobody manages themselves, and nobody manages the removed person. */
    public function manage(User $viewer, User $person): bool
    {
        if (! $viewer->isActive() || ! $viewer->isCoordinator() || $person->is_removed_person || $viewer->is($person)) {
            return false;
        }

        return ! $person->hasRole(Role::Admin) || $viewer->hasRole(Role::Admin);
    }
}
