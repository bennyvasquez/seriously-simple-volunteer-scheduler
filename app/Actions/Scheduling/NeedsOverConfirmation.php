<?php

namespace App\Actions\Scheduling;

use DomainException;

/**
 * Thrown when putting someone on a shift, or adding a regular to a weekly slot, would leave a date with more regulars than it needs. People needed is the number a shift needs, not a limit, so a coordinator can go over it, but only after a confirmation. The screens catch this, show the dates and counts, and send the same request again with the confirmation set.
 *
 * Each date is a local date (Y-m-d), the number of regulars the date would have, and the number it needs.
 */
class NeedsOverConfirmation extends DomainException
{
    /** @param  list<array{date: string, people: int, needed: int}>  $dates */
    public function __construct(public readonly array $dates)
    {
        parent::__construct('This would put '.count($dates).(count($dates) === 1 ? ' date' : ' dates').' over the number of people needed.');
    }
}
