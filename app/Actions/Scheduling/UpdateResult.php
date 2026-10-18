<?php

namespace App\Actions\Scheduling;

use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * What saving a weekly slot did to its shifts. Kept shifts are future shifts that kept their old details because someone is on them or they were edited on their own.
 */
final class UpdateResult
{
    /**
     * @param  Collection<int, Shift>  $keptShifts
     */
    public function __construct(
        public readonly int $updatedShifts,
        public readonly Collection $keptShifts,
        public readonly int $cancelledShifts = 0,
        public readonly int $createdShifts = 0,
        public readonly int $stillCancelled = 0,
    ) {}
}
