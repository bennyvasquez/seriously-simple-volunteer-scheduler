<?php

namespace App\Calendar;

use App\Models\AreaClosure;
use Illuminate\Support\Collection;

/**
 * How closures read on the week, day and month views: "Closed: Thanksgiving (Front desk)". One label per reason on a day. It names the areas unless every area shown is closed for that reason. Each label carries the closures behind it, one per area in sidebar order, so the views can link each one for people who can change it: a label with one closure is itself the link, and a label with several is followed by "Change:" and one link per area (see the calendar._closure-label partial).
 */
final class ClosureLabels
{
    /**
     * @param  Collection<int, AreaClosure>  $closures  With their area loaded.
     * @param  int  $areasShown  How many areas the view is showing.
     * @return list<array{label: string, closures: list<array{closure_id: int, area_id: int, name: string}>}> `name` is the area's name, with the closure's days added when the same area has two closures for the reason.
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

            $sorted = $group->sortBy([['area_id', 'asc'], ['id', 'asc']])->values();
            $repeated = $sorted->countBy('area_id')->filter(fn (int $count) => $count > 1);
            $labels[] = [
                'label' => $text,
                'closures' => $sorted->map(fn (AreaClosure $closure) => [
                    'closure_id' => $closure->id,
                    'area_id' => $closure->area_id,
                    'name' => $closure->area->name.($repeated->has($closure->area_id) ? ' ('.$closure->starts_on->format('M j').' to '.$closure->ends_on->format('M j').')' : ''),
                ])->all(),
            ];
        }

        return $labels;
    }
}
