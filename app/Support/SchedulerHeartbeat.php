<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * Remembers when the scheduler last ran, so `ssvs:check-install` can say whether the timer is on. It's sign-in-style machinery rather than data people change, so it has no change log entry.
 */
class SchedulerHeartbeat
{
    private const KEY = 'ssvs:scheduler-heartbeat';

    /** Stores the current time. The scheduler calls this every minute. */
    public static function beat(): void
    {
        Cache::forever(self::KEY, now()->getTimestamp());
    }

    /** When the scheduler last ran, or null if it never has. */
    public static function lastBeat(): ?CarbonImmutable
    {
        $timestamp = Cache::get(self::KEY);

        return $timestamp === null ? null : CarbonImmutable::createFromTimestamp((int) $timestamp);
    }
}
