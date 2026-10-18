<?php

namespace App\Models;

use App\Enums\SlotFrequency;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A person attached to a weekly slot from a start date to an optional end date. Both dates are included.
 *
 * On a weekly slot a regular can work every other week, counted from their own anchor date, so two people can split one slot by alternate weeks (spec section 7).
 */
class SlotRegular extends Model
{
    protected $fillable = ['weekly_slot_id', 'user_id', 'frequency', 'anchor_date', 'starts_on', 'ends_on'];

    /** Matches the column default, so a new model has it before it's saved. */
    protected $attributes = ['frequency' => 'weekly'];

    protected function casts(): array
    {
        return [
            'frequency' => SlotFrequency::class,
            'anchor_date' => 'immutable_date',
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(fn (SlotRegular $regular) => $regular->ensureValid());
    }

    public function weeklySlot(): BelongsTo
    {
        return $this->belongsTo(WeeklySlot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Throws InvalidArgumentException when an every-other-week regular has no anchor date, an anchor on the wrong weekday, or sits on a slot that already runs every other week.
     */
    public function ensureValid(): void
    {
        if ($this->frequency !== SlotFrequency::EveryOtherWeek) {
            return;
        }

        if ($this->weeklySlot->frequency === SlotFrequency::EveryOtherWeek) {
            throw new InvalidArgumentException('This slot already runs every other week, so its regulars work every date it runs.');
        }

        if ($this->anchor_date === null) {
            throw new InvalidArgumentException('An every-other-week regular needs an anchor date.');
        }

        if ($this->anchor_date->dayOfWeek !== $this->weeklySlot->weekday) {
            throw new InvalidArgumentException('The anchor date has to fall on the same weekday as the slot.');
        }
    }

    public function activeOn(string $localDate): bool
    {
        return $this->starts_on->toDateString() <= $localDate
            && ($this->ends_on === null || $localDate <= $this->ends_on->toDateString())
            && $this->frequency->includes($localDate, $this->anchor_date);
    }

    /** The first date the person actually works: the first one on or after their start date that the slot runs and their pattern includes, within the next 28 days. Null when there is none. */
    public function firstWorkedDate(): ?CarbonImmutable
    {
        $start = CarbonImmutable::parse($this->starts_on->toDateString(), 'UTC');

        for ($day = $start; $day->lessThanOrEqualTo($start->addDays(28)); $day = $day->addDay()) {
            if ($this->weeklySlot->occursOn($day->toDateString()) && $this->activeOn($day->toDateString())) {
                return $day;
            }
        }

        return null;
    }
}
