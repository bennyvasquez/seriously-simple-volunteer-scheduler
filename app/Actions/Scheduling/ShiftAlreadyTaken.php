<?php

namespace App\Actions\Scheduling;

use DomainException;

/** Someone took the last open place first (spec section 8.3 step 5). The message is written to be shown on screen. */
class ShiftAlreadyTaken extends DomainException
{
    public function __construct()
    {
        parent::__construct('Someone already took this one.');
    }
}
