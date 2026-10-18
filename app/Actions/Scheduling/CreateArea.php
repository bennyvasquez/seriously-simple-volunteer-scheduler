<?php

namespace App\Actions\Scheduling;

use App\Enums\AreaColor;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Adds an area and gives it the first color no other area uses (spec section 8). Names are unique, ignoring case. Logs area.created.
 */
class CreateArea
{
    public function handle(User $actor, string $name): Area
    {
        Gate::forUser($actor)->authorize('create', Area::class);

        $name = UpdateArea::cleanName($name);

        return DB::transaction(function () use ($actor, $name) {
            UpdateArea::ensureNameIsFree($name, null);

            $area = new Area(['name' => $name, 'color' => AreaColor::next()]);
            $area->save();

            ChangeLogEntry::record($actor, 'area.created', $area, [], [
                'name' => ['old' => null, 'new' => $area->name],
                'color' => ['old' => null, 'new' => $area->color->value],
            ]);

            return $area;
        });
    }
}
