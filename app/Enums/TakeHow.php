<?php

namespace App\Enums;

/** How a volunteer takes an open shift (spec section 8.1 step 2): just this date, or as the regular every week or every other week from it. */
enum TakeHow: string
{
    case JustThisDate = 'date';
    case EveryWeek = 'weekly';
    case EveryOtherWeek = 'every_other';
}
