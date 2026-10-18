<?php

namespace App\Calendar;

use App\Enums\SlotFrequency;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The sidebar's "Needs attention" list (plan decision): for the next 14 days, shifts with open places (marked urgent inside 24 hours) and rotation shifts nobody signed up for, then weekly slots with no regular. "Ready for review" waits for clearance.
 */
final class NeedsAttention
{
    public const DAYS = 14;

    /**
     * @param  array<string, mixed>  $linkQuery  Query kept on every link, such as the areas[] filter.
     * @return Collection<int, array{label: string, detail: string, url: ?string, urgent: bool}>
     */
    public static function for(Organization $organization, array $areaIds, array $linkQuery = []): Collection
    {
        $timezone = $organization->timezone;
        $now = CarbonImmutable::now();
        $today = $organization->today()->toDateString();
        $tomorrow = $organization->today()->addDay()->toDateString();

        $shifts = Shift::query()
            ->active()
            ->whereIn('area_id', $areaIds)
            ->where('starts_at', '>', $now)
            ->where('starts_at', '<=', $now->addDays(self::DAYS))
            ->with(['area', 'assignments', 'weeklySlot'])
            ->orderBy('starts_at')
            ->get()
            ->filter(fn (Shift $shift) => $shift->openPlaces() > 0);

        $items = $shifts->map(function (Shift $shift) use ($timezone, $now, $today, $tomorrow, $linkQuery) {
            $date = $shift->local_date->toDateString();
            $urgent = $shift->isUrgent($now);

            if ($shift->weeklySlot?->is_rotation && $shift->filledCount() === 0) {
                $detail = $shift->area->name.' rotation, nobody signed up';
            } else {
                $detail = $shift->area->name.', '.$shift->openPlaces().' open';
            }

            if ($urgent) {
                $detail .= $date === $today ? ', starts today' : ($date === $tomorrow ? ', starts tomorrow' : ', starts soon');
            }

            return [
                'label' => $shift->starts_at->setTimezone($timezone)->format('D M j').', '.TimeFormat::time($shift->starts_at->setTimezone($timezone)),
                'detail' => $detail,
                'url' => self::shiftUrl($shift, $linkQuery),
                'urgent' => $urgent,
            ];
        })->values();

        return $items->concat(self::slotsWithoutRegular($today, $areaIds, $linkQuery));
    }

    /** @return Collection<int, array{label: string, detail: string, url: ?string, urgent: bool}> */
    private static function slotsWithoutRegular(string $today, array $areaIds, array $linkQuery): Collection
    {
        $days = [];
        $first = CarbonImmutable::parse($today, 'UTC');
        for ($offset = 0; $offset < self::DAYS; $offset++) {
            $days[] = $first->addDays($offset)->toDateString();
        }

        return WeeklySlot::query()
            ->whereIn('area_id', $areaIds)
            ->where('is_rotation', false)
            ->where('valid_from', '<=', $days[self::DAYS - 1])
            ->where(fn ($query) => $query->whereNull('valid_until')->orWhere('valid_until', '>=', $today))
            // A regular counts only if they cover a date in the window.
            ->whereDoesntHave('regulars', fn ($query) => $query
                ->where('starts_on', '<=', $days[self::DAYS - 1])
                ->where(fn ($ends) => $ends->whereNull('ends_on')->orWhere('ends_on', '>=', $today)))
            ->with('area')
            ->orderBy('weekday')
            ->orderBy('start_time')
            ->get()
            ->map(function (WeeklySlot $slot) use ($days, $linkQuery) {
                $next = collect($days)->first(fn (string $date) => $slot->occursOn($date));

                if ($next === null) {
                    return null;
                }

                $weekday = CarbonImmutable::parse($next)->format('l');
                $when = $slot->frequency === SlotFrequency::EveryOtherWeek ? 'every other '.$weekday : $weekday.'s';
                $time = TimeFormat::range(CarbonImmutable::parse($slot->start_time), CarbonImmutable::parse($slot->end_time));

                return [
                    'label' => $slot->area->name.', '.$when.' '.$time,
                    'detail' => 'No regular',
                    'url' => route('calendar.week', ['date' => $next] + $linkQuery),
                    'urgent' => false,
                ];
            })
            ->filter()
            ->values();
    }

    /** The shift's details. */
    private static function shiftUrl(Shift $shift, array $linkQuery): string
    {
        return route('calendar.shift', ['shift' => $shift] + $linkQuery);
    }
}
