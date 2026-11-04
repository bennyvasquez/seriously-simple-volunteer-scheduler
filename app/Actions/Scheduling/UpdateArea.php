<?php

namespace App\Actions\Scheduling;

use App\Enums\AreaColor;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Renames an area, changes its color or changes what it calls its leads (blank means "Area lead", stored as null). Names are unique, ignoring case. Logs area.updated with the old and new values, and nothing when nothing changed.
 */
class UpdateArea
{
    public function handle(User $actor, Area $area, string $name, AreaColor $color, ?string $leadName = null): Area
    {
        Gate::forUser($actor)->authorize('update', $area);

        $name = self::cleanName($name);
        $leadName = trim((string) $leadName);

        if (mb_strlen($leadName) > 40) {
            throw ValidationException::withMessages(['lead_name' => 'Keep it to 40 characters or fewer.']);
        }

        return DB::transaction(function () use ($actor, $area, $name, $color, $leadName) {
            // Every area row, once, in id order, so two people editing different areas at once can't deadlock. The area rows come before anything else in the lock order.
            $areas = Area::query()->orderBy('id')->lockForUpdate()->get();
            $area = $areas->firstWhere('id', $area->id) ?? throw (new ModelNotFoundException)->setModel(Area::class, [$area->id]);
            self::assertNameIsFree($areas, $name, $area->id);

            $area->name = $name;
            $area->color = $color;
            $area->lead_name = $leadName === '' ? null : $leadName;
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
        self::assertNameIsFree(Area::query()->orderBy('id')->lockForUpdate()->get(['id', 'name']), $name, $exceptId);
    }

    /** @param  Collection<int, Area>  $areas */
    private static function assertNameIsFree(Collection $areas, string $name, ?int $exceptId): void
    {
        $taken = $areas->first(fn (Area $other) => $other->id !== $exceptId && mb_strtolower($other->name) === mb_strtolower($name));

        if ($taken !== null) {
            throw ValidationException::withMessages(['name' => "There's already an area called {$taken->name}."]);
        }
    }
}
