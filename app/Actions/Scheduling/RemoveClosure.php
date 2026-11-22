<?php

namespace App\Actions\Scheduling;

use App\Cover\CoverFill;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use DomainException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes a closure, whether or not it has started (spec section 8.5). Its cancelled shifts that haven't started are released the way UpdateClosure releases days it no longer covers: handed to another closure covering the day, left cancelled when the weekly slot no longer runs that day, or brought back. Days that have passed only lose the "Closed" label: their shifts stay cancelled with their reason, and their area_closure_id clears through the foreign key's nullOnDelete. Records closure.removed with the closure's old values, then deletes the row, in one transaction locking the area, the closure, the area's weekly slots and their regulars, and then its shifts. After it commits, the area's weekly slots are generated again, and after the response each person gets at most one email about the shifts that are back on. A regular who comes back onto a released shift fills its cover request, and the releaser hears who is covering.
 */
class RemoveClosure
{
    /**
     * What the last handle() did, for the status line: the shifts brought back, how many people are emailed, and who was removed from a shift that came back because they're now on another shift at that time.
     *
     * @var array{restored: Collection<int, Shift>, emailed: int, removed: Collection<int, User>}
     */
    public array $outcome;

    public function __construct(private readonly UpdateClosure $updateClosure)
    {
        $this->outcome = ['restored' => collect(), 'emailed' => 0, 'removed' => collect()];
    }

    public function handle(User $actor, AreaClosure $closure): void
    {
        Gate::forUser($actor)->authorize('close', $closure->area);

        [$area, $released, $endsOn] = DB::transaction(function () use ($actor, $closure) {
            $area = Area::query()->whereKey($closure->area_id)->lockForUpdate()->firstOrFail();
            $locked = AreaClosure::query()->whereKey($closure->id)->lockForUpdate()->first();

            if ($locked === null) {
                throw new DomainException('This closure was removed.');
            }

            $locked->setRelation('area', $area);
            UpdateClosure::lockSlotsAndRegulars($area);

            $shifts = Shift::query()
                ->where('area_closure_id', $locked->id)
                ->whereNotNull('cancelled_at')
                ->where('starts_at', '>', now())
                ->orderBy('starts_at')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $released = $this->updateClosure->releaseLocked($actor, $locked, $shifts, 'closure.removed');

            $changes = [];
            foreach (['area_id', 'starts_on', 'ends_on', 'reason'] as $key) {
                $changes[$key] = ['old' => $locked->getRawOriginal($key), 'new' => null];
            }

            ChangeLogEntry::record($actor, 'closure.removed', $locked, [
                'area_id' => $area->id,
                'restored_shifts' => array_map(fn (Shift $shift) => $shift->id, $released['restored']),
            ], $changes);

            $locked->delete();

            return [$area, $released, $locked->ends_on->toDateString()];
        });

        // Only a closure that still covered today or later kept days from being made.
        if ($endsOn >= Organization::current()->today()->toDateString()) {
            $this->updateClosure->generate($actor, $area, 'closure.removed');
        }

        $this->outcome['restored'] = collect($released['restored']);
        $this->outcome['removed'] = $released['removed'];
        $this->outcome['emailed'] = UpdateClosure::sendEmails([], $released['restoredByPerson'], '');
        CoverFill::sendAfterCommit($released['covered']);
    }
}
