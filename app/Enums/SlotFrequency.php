<?php

namespace App\Enums;

use Carbon\CarbonImmutable;

enum SlotFrequency: string
{
    case Weekly = 'weekly';
    case EveryOtherWeek = 'every_other_week';

    /**
     * Whether a local date (Y-m-d) falls in this pattern's weeks. Every other week counts whole weeks from the anchor date, in either direction. Callers check the weekday separately.
     */
    public function includes(string $localDate, ?CarbonImmutable $anchorDate): bool
    {
        if ($this === self::Weekly) {
            return true;
        }

        $weeksFromAnchor = intdiv((int) abs($anchorDate->diffInDays(CarbonImmutable::parse($localDate, 'UTC'))), 7);

        return $weeksFromAnchor % 2 === 0;
    }
}
