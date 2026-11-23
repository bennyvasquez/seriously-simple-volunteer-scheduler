<?php

namespace App\Actions;

use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Coordinators change how many shadow shifts someone does before a coordinator reviews them (1 to 10). Raising it never un-clears anyone, and lowering it shows people who now meet it as ready for review straight away, because the count is read when the list is shown. Logs organization.updated, the same way UpdateSettings does.
 */
class UpdateShadowingSettings
{
    public function handle(User $actor, int $requiredShadowShifts): Organization
    {
        Gate::forUser($actor)->authorize('manage-shadowing');

        if ($requiredShadowShifts < 1 || $requiredShadowShifts > 10) {
            throw ValidationException::withMessages(['required_shadow_shifts' => 'Choose a number from 1 to 10.']);
        }

        return DB::transaction(function () use ($actor, $requiredShadowShifts) {
            $organization = Organization::query()->lockForUpdate()->firstOrFail();

            $organization->fill(['required_shadow_shifts' => $requiredShadowShifts]);
            $changes = ChangeLogEntry::changesOf($organization);

            if ($changes !== []) {
                $organization->save();
                ChangeLogEntry::record($actor, 'organization.updated', $organization, [], $changes);
            }

            return $organization;
        });
    }
}
