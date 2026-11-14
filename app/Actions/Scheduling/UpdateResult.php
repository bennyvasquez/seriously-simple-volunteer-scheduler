<?php

namespace App\Actions\Scheduling;

use App\Models\Shift;
use Illuminate\Support\Collection;

/**
 * What saving a weekly slot did to its shifts. Updated shifts include dates brought back after an earlier slot change cancelled them, and shifts people are on that took the slot's new details. Kept shifts are future shifts that kept their old details: ones edited on their own, ones the slot no longer runs on, and ones people are on where the change failed a StaffedShiftChecks check.
 */
final class UpdateResult
{
    /**
     * @param  Collection<int, Shift>  $keptShifts
     * @param  array<int, string>  $keptReasons  Each kept shift's id to why it was kept, worded to show on screen: the StaffedShiftChecks message, "Changed on its own" or "The slot doesn't run on this date any more".
     * @param  int  $emailed  How many people who can sign in are emailed about their changed shifts.
     */
    public function __construct(
        public readonly int $updatedShifts,
        public readonly Collection $keptShifts,
        public readonly int $cancelledShifts = 0,
        public readonly int $createdShifts = 0,
        public readonly array $keptReasons = [],
        public readonly int $emailed = 0,
    ) {}
}
