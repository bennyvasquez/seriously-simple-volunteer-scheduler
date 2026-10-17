<?php

namespace App\Calendar;

use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Organization;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * One month for screen 33: Sunday-first weeks covering the month, and for each day of the month the number of shifts per area, the open places and the closures that cover it. Days from the months before and after are shown but left empty.
 */
final class CalendarMonth
{
    /**
     * @param  list<list<array<string, mixed>>>  $weeks
     */
    private function __construct(
        public readonly CarbonImmutable $month,
        private readonly array $weeks,
    ) {}

    /**
     * @param  string  $yearMonth  Y-m, such as 2026-11.
     * @param  Collection<int, Area>  $areas  The areas being shown, in sidebar order.
     */
    public static function for(string $yearMonth, Organization $organization, Collection $areas): self
    {
        $month = CarbonImmutable::createFromFormat('!Y-m', $yearMonth, 'UTC');
        $first = $month->startOfMonth();
        $last = $month->endOfMonth()->startOfDay();
        $areaIds = $areas->modelKeys();

        $shifts = Shift::query()
            ->active()
            ->whereIn('area_id', $areaIds)
            ->whereBetween('local_date', [$first->toDateString(), $last->toDateString()])
            ->with('assignments')
            ->get()
            ->groupBy(fn (Shift $shift) => $shift->local_date->toDateString());

        $closures = AreaClosure::query()
            ->whereIn('area_id', $areaIds)
            ->where('starts_on', '<=', $last->toDateString())
            ->where('ends_on', '>=', $first->toDateString())
            ->orderBy('starts_on')
            ->get();

        $weeks = [];
        $day = $first->startOfWeek(CarbonInterface::SUNDAY);
        $gridEnd = $last->endOfWeek(CarbonInterface::SATURDAY)->startOfDay();

        while ($day->lessThanOrEqualTo($gridEnd)) {
            $week = [];
            for ($i = 0; $i < 7; $i++, $day = $day->addDay()) {
                $week[] = self::describeDay($day, $month, $shifts, $closures, $areas, count($weeks) === 0 && $i === 0);
            }
            $weeks[] = $week;
        }

        return new self($month, $weeks);
    }

    /** @return list<list<array{date: string, label: string, inMonth: bool, counts: list<array{area: Area, text: string}>, open: int, closures: list<string>}>> */
    public function weeks(): array
    {
        return $this->weeks;
    }

    /**
     * @param  Collection<string, Collection<int, Shift>>  $shifts
     * @param  Collection<int, AreaClosure>  $closures
     * @param  Collection<int, Area>  $areas
     */
    private static function describeDay(CarbonImmutable $day, CarbonImmutable $month, Collection $shifts, Collection $closures, Collection $areas, bool $firstCell): array
    {
        $date = $day->toDateString();
        $inMonth = $day->month === $month->month;

        // The first day shown from another month carries its month name, like "Dec 1".
        $label = ! $inMonth && ($day->day === 1 || $firstCell) ? $day->format('M j') : (string) $day->day;

        if (! $inMonth) {
            return ['date' => $date, 'label' => $label, 'inMonth' => false, 'counts' => [], 'open' => 0, 'closures' => []];
        }

        $onDay = $shifts->get($date, new Collection);

        $counts = [];
        foreach ($areas as $area) {
            $count = $onDay->where('area_id', $area->id)->count();
            if ($count > 0) {
                $counts[] = ['area' => $area, 'text' => $count.' '.$area->name.' '.Str::plural('shift', $count)];
            }
        }

        // One label per reason. It names the areas unless every area shown is closed for it.
        $closed = [];
        foreach ($closures->filter(fn (AreaClosure $closure) => $closure->covers($date))->groupBy('reason') as $reason => $group) {
            $closedAreaIds = $group->pluck('area_id')->unique();
            $text = 'Closed: '.$reason;
            if ($closedAreaIds->count() < $areas->count()) {
                $text .= ' ('.$areas->whereIn('id', $closedAreaIds)->pluck('name')->implode(', ').')';
            }
            $closed[] = $text;
        }

        return [
            'date' => $date,
            'label' => $label,
            'inMonth' => true,
            'counts' => $counts,
            'open' => $onDay->sum(fn (Shift $shift) => $shift->openPlaces()),
            'closures' => $closed,
        ];
    }
}
