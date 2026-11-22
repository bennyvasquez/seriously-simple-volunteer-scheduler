<?php

namespace App\Enums;

/**
 * What a shadow is told about their spot when the people they'd shadow change (decision 6). On hold when nobody but shadows is left on the shift, back on when someone takes it, and still uncovered at 24 hours.
 */
enum ShadowSpotNotice: string
{
    case OnHold = 'on_hold';
    case BackOn = 'back_on';
    case StillUncovered = 'still_uncovered';
}
