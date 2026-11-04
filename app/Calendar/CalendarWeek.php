<?php

namespace App\Calendar;

use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * The shifts for one week (Sunday to Saturday) or one day, for the areas being shown, laid out for the time grid on screens 30 and 34. Every time is in the organization's time zone.
 *
 * All the shifts come from one query with their area, people, slot and slot regulars eager-loaded, so the page's query count doesn't grow with the number of shifts.
 */
final class CalendarWeek
{
    /** Pixels per hour on the grid, matching the hour lines in app.css. */
    public const HOUR_HEIGHT = 48;

    /** The grid always shows at least these hours. */
    private const EARLIEST_START = 8;

    private const LATEST_END = 22;

    /** @var array{0: int, 1: int}|null */
    private ?array $hours = null;

    /**
     * @param  list<string>  $days
     * @param  Collection<string, Collection<int, Shift>>  $shiftsByDay
     * @param  Collection<int, AreaClosure>  $closures  Closures of the areas shown that cover any of the days.
     * @param  int  $areasShown  How many of the asked-for areas exist, so a closure label names its areas unless every area shown is closed.
     */
    private function __construct(
        public readonly Organization $organization,
        private readonly array $days,
        private readonly Collection $shiftsByDay,
        private readonly Collection $closures,
        private readonly int $areasShown,
    ) {}

    /** The Sunday-to-Saturday week holding the given date. Only the date part is used. */
    public static function for(CarbonImmutable $anyDayInWeek, Organization $organization, array $areaIds): self
    {
        $sunday = CarbonImmutable::parse($anyDayInWeek->toDateString(), 'UTC')->startOfWeek(CarbonInterface::SUNDAY);

        $days = [];
        for ($offset = 0; $offset < 7; $offset++) {
            $days[] = $sunday->addDays($offset)->toDateString();
        }

        return self::load($organization, $days, $areaIds);
    }

    /** One day on its own, for the day view. */
    public static function forDay(CarbonImmutable $day, Organization $organization, array $areaIds): self
    {
        return self::load($organization, [$day->toDateString()], $areaIds);
    }

    /** @param  list<string>  $days */
    private static function load(Organization $organization, array $days, array $areaIds): self
    {
        $shifts = Shift::query()
            ->whereIn('area_id', $areaIds)
            ->whereBetween('local_date', [$days[0], $days[count($days) - 1]])
            ->with(['area', 'assignments.user', 'weeklySlot.regulars'])
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get();

        $closures = AreaClosure::query()
            ->whereIn('area_id', $areaIds)
            ->where('starts_on', '<=', $days[count($days) - 1])
            ->where('ends_on', '>=', $days[0])
            ->with('area')
            ->orderBy('starts_on')
            ->orderBy('id')
            ->get();

        return new self($organization, $days, $shifts->groupBy(fn (Shift $shift) => $shift->local_date->toDateString()), $closures, Area::query()->whereKey($areaIds)->count());
    }

    /** @return list<string> Y-m-d dates, Sunday first (or the one day). */
    public function days(): array
    {
        return $this->days;
    }

    /** @return Collection<int, Shift> Shifts on a local date, cancelled ones included, ordered by start. */
    public function shiftsOn(string $date): Collection
    {
        return $this->shiftsByDay->get($date, new Collection);
    }

    /** @return list<string> "Closed: Thanksgiving (Front desk)" for each reason the areas shown are closed on a local date. */
    public function closuresOn(string $date): array
    {
        return ClosureLabels::on($this->closures, $date, $this->areasShown);
    }

    /** Whether a closure covers any of the days, so the grid needs its all-day row. */
    public function hasClosures(): bool
    {
        return $this->closures->isNotEmpty();
    }

    public function isEmpty(): bool
    {
        return $this->shiftsByDay->isEmpty();
    }

    /**
     * From an hour before the earliest start to an hour after the latest end, and at least 8am to 10pm. A shift that runs past midnight counts as ending at midnight.
     *
     * @return array{0: int, 1: int} First and last hour, 0 to 24.
     */
    public function hourRange(): array
    {
        return $this->hours ??= $this->computeHourRange();
    }

    /** @return array{0: int, 1: int} */
    private function computeHourRange(): array
    {
        $shifts = $this->shiftsByDay->flatten(1);

        if ($shifts->isEmpty()) {
            return [self::EARLIEST_START, self::LATEST_END];
        }

        $earliest = $shifts->min(fn (Shift $shift) => $this->startMinute($shift));
        $latest = $shifts->max(fn (Shift $shift) => $this->endMinute($shift));

        return [
            max(0, min(self::EARLIEST_START, intdiv($earliest, 60) - 1)),
            min(24, max(self::LATEST_END, (int) ceil($latest / 60) + 1)),
        ];
    }

    /**
     * Places overlapping shifts side by side. Shifts that overlap, directly or through another shift, form a group, and each shift takes the first column free when it starts.
     *
     * @param  Collection<int, Shift>  $shifts
     * @return array<int, array{0: int, 1: int}> Shift id to [column, columns in its group], counting columns from 0.
     */
    public function columnsFor(Collection $shifts): array
    {
        $result = [];
        $group = [];
        $columnEnds = [];
        $groupEnd = null;

        $flush = function () use (&$result, &$group, &$columnEnds, &$groupEnd) {
            foreach ($group as $id => $column) {
                $result[$id] = [$column, count($columnEnds)];
            }
            $group = [];
            $columnEnds = [];
            $groupEnd = null;
        };

        $sorted = $shifts->sortBy([fn (Shift $a, Shift $b) => $a->starts_at <=> $b->starts_at, fn (Shift $a, Shift $b) => $b->ends_at <=> $a->ends_at]);

        foreach ($sorted as $shift) {
            $start = $shift->starts_at->getTimestamp();
            $end = $shift->ends_at->getTimestamp();

            if ($groupEnd !== null && $start >= $groupEnd) {
                $flush();
            }

            $column = count($columnEnds);
            foreach ($columnEnds as $index => $columnEnd) {
                if ($columnEnd <= $start) {
                    $column = $index;
                    break;
                }
            }

            $columnEnds[$column] = $end;
            $group[$shift->id] = $column;
            $groupEnd = max($groupEnd ?? $end, $end);
        }

        $flush();

        return $result;
    }

    /** Pixels from the top of the grid to the shift's start. */
    public function top(Shift $shift): int
    {
        return intdiv(($this->startMinute($shift) - $this->hourRange()[0] * 60) * self::HOUR_HEIGHT, 60);
    }

    /** The block's height in pixels, cut off at the bottom of the grid and never too short to read. */
    public function height(Shift $shift): int
    {
        $minutes = min($this->endMinute($shift), $this->hourRange()[1] * 60) - $this->startMinute($shift);

        return max(intdiv(self::HOUR_HEIGHT, 2), intdiv($minutes * self::HOUR_HEIGHT, 60));
    }

    /**
     * What a shift block says, for the grid and the phone list.
     *
     * @return array{time: string, area: string, lines: list<string>, classes: string, label: string}
     */
    public function describe(Shift $shift, CarbonImmutable $now): array
    {
        $timezone = $this->organization->timezone;
        $date = $shift->local_date->toDateString();
        $open = $shift->openPlaces();
        $urgent = $shift->isUrgent($now);

        $regulars = $shift->assignments->where('type', AssignmentType::Regular)->map(fn ($assignment) => $assignment->user->firstName());
        $shadows = $shift->assignments->where('type', AssignmentType::Shadow)->map(fn ($assignment) => $assignment->user->firstName());

        $cancelled = $shift->cancelled_at !== null;
        $lines = [];

        $title = $shift->title ?? ($shift->weeklySlot?->is_rotation ? 'Rotation' : null);
        if ($title !== null) {
            $lines[] = $title;
        }

        if ($cancelled) {
            // A cancelled date shows only what happened. Nobody is open, urgent or missing a regular on it.
            $lines = [...$lines, 'Cancelled: '.$shift->cancel_reason];
            $regulars = collect();
            $shadows = collect();
        }

        $people = $regulars->values()->all();
        if ($open > 0) {
            $openText = "{$open} open";
            if ($urgent) {
                $openText .= ', '.($date === CarbonImmutable::now($timezone)->toDateString() ? 'today' : 'tomorrow');
            }
            $people[] = $openText;
        }
        if ($people !== []) {
            $lines[] = implode(', ', $people);
        }

        if ($shadows->isNotEmpty()) {
            $lines[] = ($shadows->count() === 1 ? 'Shadow: ' : 'Shadows: ').$shadows->implode(', ');
        }

        $openShadowSpots = $cancelled ? 0 : max(0, $shift->shadow_spots - $shadows->count());
        if ($openShadowSpots > 0) {
            $lines[] = $openShadowSpots === 1 ? '1 shadow spot open' : "{$openShadowSpots} shadow spots open";
        }

        $slot = $shift->weeklySlot;
        if (! $cancelled && $slot !== null && ! $slot->is_rotation && ! $slot->regulars->contains(fn (SlotRegular $regular) => $regular->activeOn($date))) {
            $lines[] = 'No regular';
        }

        $classes = 'ev '.$shift->area->color->cssClass();
        if ($cancelled) {
            $classes .= ' cancelled';
        }
        if ($open > 0) {
            $classes .= ' open';
        }
        if ($urgent) {
            $classes .= ' urgent';
        }

        $time = TimeFormat::range($shift->starts_at->setTimezone($timezone), $shift->ends_at->setTimezone($timezone));

        // The visible spans run together for screen readers, so the block gets one name read as a sentence.
        $spoken = array_map(fn (string $line) => preg_replace_callback('/^(Shadows?): /', fn (array $match) => strtolower($match[1]).' ', $line), $lines);
        $label = implode(', ', [$shift->area->name, $shift->local_date->format('l, F j'), $time, ...$spoken]);

        return [
            'label' => $label,
            'time' => $time,
            'area' => $shift->area->name,
            'lines' => $lines,
            'classes' => $classes,
        ];
    }

    /** Minutes from local midnight on the shift's date to its start, by the wall clock. */
    private function startMinute(Shift $shift): int
    {
        $start = $shift->starts_at->setTimezone($this->organization->timezone);

        return $start->hour * 60 + $start->minute;
    }

    /** Minutes from local midnight to the shift's end, by the wall clock; 1440 when it ends the next day. */
    private function endMinute(Shift $shift): int
    {
        $end = $shift->ends_at->setTimezone($this->organization->timezone);

        if ($end->toDateString() > $shift->local_date->toDateString()) {
            return 24 * 60;
        }

        return $end->hour * 60 + $end->minute;
    }
}
