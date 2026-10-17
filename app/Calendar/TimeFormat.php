<?php

namespace App\Calendar;

use Carbon\CarbonImmutable;

/**
 * How times and dates read on the calendar: "6:30pm", "6 to 9pm", "11:30am to 2:30pm", "October 18 to 24, 2026". Callers pass times already in the organization's time zone.
 */
final class TimeFormat
{
    /** "6pm" or "6:30pm". */
    public static function time(CarbonImmutable $time): string
    {
        return $time->format($time->minute === 0 ? 'ga' : 'g:ia');
    }

    /** "6 to 9pm" when both ends share am or pm, "11:30am to 2:30pm" when they don't. */
    public static function range(CarbonImmutable $start, CarbonImmutable $end): string
    {
        $from = self::time($start);

        if ($start->format('a') === $end->format('a')) {
            $from = substr($from, 0, -2);
        }

        return $from.' to '.self::time($end);
    }

    /**
     * "October 18 to 24, 2026", "October 25 to November 1, 2026" or "December 27, 2026 to January 2, 2027".
     *
     * @param  list<string>  $days  Y-m-d dates, first to last.
     */
    public static function days(array $days): string
    {
        $first = CarbonImmutable::parse($days[0]);
        $last = CarbonImmutable::parse($days[count($days) - 1]);

        if ($first->equalTo($last)) {
            return $first->format('l, F j, Y');
        }

        if ($first->year !== $last->year) {
            return $first->format('F j, Y').' to '.$last->format('F j, Y');
        }

        if ($first->month !== $last->month) {
            return $first->format('F j').' to '.$last->format('F j, Y');
        }

        return $first->format('F j').' to '.$last->format('j, Y');
    }
}
