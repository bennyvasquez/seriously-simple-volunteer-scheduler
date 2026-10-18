<?php

namespace App\Calendar;

use App\Models\AreaClosure;
use Illuminate\Support\Collection;

/**
 * How closures read on the week, day and month views: "Closed: Thanksgiving (Front desk)". One label per reason on a day. It names the areas unless every area shown is closed for that reason.
 */
final class ClosureLabels
{
    /**
     * @param  Collection<int, AreaClosure>  $closures  With their area loaded.
     * @param  int  $areasShown  How many areas the view is showing.
     * @return list<string>
     */
    public static function on(Collection $closures, string $date, int $areasShown): array
    {
        $labels = [];

        foreach ($closures->filter(fn (AreaClosure $closure) => $closure->covers($date))->groupBy('reason') as $reason => $group) {
            $areas = $group->pluck('area')->unique('id')->sortBy('id');
            $text = 'Closed: '.$reason;

            if ($areas->count() < $areasShown) {
                $text .= ' ('.$areas->pluck('name')->implode(', ').')';
            }

            $labels[] = $text;
        }

        return $labels;
    }
}
