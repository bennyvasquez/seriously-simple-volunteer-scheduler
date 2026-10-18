<?php

namespace App\Actions\Scheduling;

use App\Enums\AreaColor;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Renames an area or changes its color. Names are unique, ignoring case. Logs area.updated with the old and new values, and nothing when nothing changed.
 */
class UpdateArea
{
    public function handle(User $actor, Area $area, string $name, AreaColor $color): Area
    {
        Gate::forUser($actor)->authorize('update', $area);

        $name = self::cleanName($name);

        return DB::transaction(function () use ($actor, $area, $name, $color) {
            // The area row first, the same lock order as everything that touches an area's shifts.
            $area = Area::query()->whereKey($area->id)->lockForUpdate()->firstOrFail();
            self::ensureNameIsFree($name, $area->id);

            $area->name = $name;
            $area->color = $color;
            $changes = ChangeLogEntry::changesOf($area);

            if ($changes !== []) {
                $area->save();
                ChangeLogEntry::record($actor, 'area.updated', $area, [], $changes);
            }

            return $area;
        });
    }

    public static function cleanName(string $name): string
    {
        $name = trim($name);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Give the area a name.']);
        }

        if (mb_strlen($name) > 100) {
            throw ValidationException::withMessages(['name' => 'Keep the name to 100 characters or fewer.']);
        }

        return $name;
    }

    /** Locks every area name while it checks, so two people adding the same name at once can't both succeed. */
    public static function ensureNameIsFree(string $name, ?int $exceptId): void
    {
        $taken = Area::query()->lockForUpdate()->get(['id', 'name'])
            ->first(fn (Area $other) => $other->id !== $exceptId && mb_strtolower($other->name) === mb_strtolower($name));

        if ($taken !== null) {
            throw ValidationException::withMessages(['name' => "There's already an area called {$taken->name}."]);
        }
    }
}
