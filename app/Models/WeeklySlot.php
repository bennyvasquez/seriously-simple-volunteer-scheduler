<?php

namespace App\Models;

use App\Calendar\TimeFormat;
use App\Enums\SlotFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * A repeating shift template (spec section 5). Times are local wall-clock times, so a weekly shift doesn't move by an hour when daylight saving time changes.
 *
 * Dates are passed around as Y-m-d strings and compared as strings. Comparing them as instants would mix the organization's midnight with UTC midnight and drop the last day of a range.
 */
class WeeklySlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'area_id', 'weekday', 'start_time', 'end_time', 'headcount', 'shadow_spots',
        'frequency', 'anchor_date', 'valid_from', 'valid_until', 'title', 'is_rotation',
    ];

    protected function casts(): array
    {
        return [
            'weekday' => 'integer',
            'headcount' => 'integer',
            'shadow_spots' => 'integer',
            'is_rotation' => 'boolean',
            'frequency' => SlotFrequency::class,
            'anchor_date' => 'immutable_date',
            'valid_from' => 'immutable_date',
            'valid_until' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (WeeklySlot $slot) {
            if ($slot->weekday < 0 || $slot->weekday > 6) {
                throw new InvalidArgumentException('Weekday must be from 0 (Sunday) to 6 (Saturday).');
            }

            if ($slot->start_time === $slot->end_time) {
                throw new InvalidArgumentException('A slot has to end at a different time than it starts.');
            }

            if ($slot->headcount < 1) {
                throw new InvalidArgumentException('A slot needs at least one person.');
            }

            if ($slot->frequency === SlotFrequency::EveryOtherWeek) {
                if ($slot->anchor_date === null) {
                    throw new InvalidArgumentException('An every-other-week slot needs an anchor date.');
                }

                if ($slot->anchor_date->dayOfWeek !== $slot->weekday) {
                    throw new InvalidArgumentException('The anchor date has to fall on the same weekday as the slot.');
                }
            }
        });
    }

    protected function startTime(): Attribute
    {
        return Attribute::make(set: fn (string $value) => CarbonImmutable::parse($value)->format('H:i:s'));
    }

    protected function endTime(): Attribute
    {
        return Attribute::make(set: fn (string $value) => CarbonImmutable::parse($value)->format('H:i:s'));
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function regulars(): HasMany
    {
        return $this->hasMany(SlotRegular::class);
    }

    public function shifts(): HasMany
    {
        return $this->hasMany(Shift::class);
    }

    /** Whether this slot has a shift on a local date (Y-m-d). */
    public function occursOn(string $localDate): bool
    {
        $day = CarbonImmutable::parse($localDate, 'UTC');

        if ($day->dayOfWeek !== $this->weekday) {
            return false;
        }

        if ($localDate < $this->valid_from->toDateString()) {
            return false;
        }

        if ($this->valid_until !== null && $localDate > $this->valid_until->toDateString()) {
            return false;
        }

        return $this->frequency->includes($localDate, $this->anchor_date);
    }

    /**
     * The local dates (Y-m-d) from $from to $to, both included, that this slot occurs on.
     *
     * @return list<string>
     */
    public function datesWithin(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $dates = [];

        for ($day = $from; $day->lessThanOrEqualTo($to); $day = $day->addDay()) {
            if ($this->occursOn($day->toDateString())) {
                $dates[] = $day->toDateString();
            }
        }

        return $dates;
    }

    /** "Thursday". */
    public function weekdayName(): string
    {
        return CarbonImmutable::parse('2026-10-18', 'UTC')->addDays($this->weekday)->format('l');
    }

    /** The slot in words: "Thursdays, 6:30 to 9:30pm" or "Every other Thursday, 6:30 to 9:30pm". */
    public function describe(): string
    {
        $weekday = $this->weekdayName();
        $when = $this->frequency === SlotFrequency::Weekly ? $weekday.'s' : 'Every other '.$weekday;

        return $when.', '.TimeFormat::range(CarbonImmutable::parse($this->start_time), CarbonImmutable::parse($this->end_time));
    }

    /**
     * Start and end of this slot on a local date, converted to UTC.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function timesOn(string $localDate, string $timezone): array
    {
        $startsAt = CarbonImmutable::parse("{$localDate} {$this->start_time}", $timezone);

        $endDate = $this->end_time < $this->start_time
            ? CarbonImmutable::parse($localDate, 'UTC')->addDay()->toDateString()
            : $localDate;

        $endsAt = CarbonImmutable::parse("{$endDate} {$this->end_time}", $timezone);

        return [$startsAt->utc(), $endsAt->utc()];
    }
}
