<?php

namespace App\Models;

use App\Calendar\TimeFormat;
use App\Enums\AssignmentType;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One dated occurrence of a shift. Start and end are stored in UTC; local_date is the date in the organization's time zone.
 */
class Shift extends Model
{
    protected $fillable = [
        'area_id', 'weekly_slot_id', 'local_date', 'starts_at', 'ends_at', 'headcount', 'shadow_spots',
        'title', 'cancelled_at', 'cancel_reason', 'area_closure_id', 'edited_at',
    ];

    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'headcount' => 'integer',
            'shadow_spots' => 'integer',
            'cancelled_at' => 'immutable_datetime',
            'edited_at' => 'immutable_datetime',
        ];
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function weeklySlot(): BelongsTo
    {
        return $this->belongsTo(WeeklySlot::class);
    }

    public function areaClosure(): BelongsTo
    {
        return $this->belongsTo(AreaClosure::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /** Shifts that haven't been cancelled. */
    public function scopeActive(Builder $query): void
    {
        $query->whereNull('cancelled_at');
    }

    /** People filling a place (regulars and one-offs, not shadows). Uses the loaded assignments when present. */
    public function filledCount(): int
    {
        if ($this->relationLoaded('assignments')) {
            return $this->assignments->where('type', AssignmentType::Regular)->count();
        }

        return $this->assignments()->where('type', AssignmentType::Regular)->count();
    }

    public function openPlaces(): int
    {
        // A cancelled date has no places to fill.
        if ($this->cancelled_at !== null) {
            return 0;
        }

        return max(0, $this->headcount - $this->filledCount());
    }

    /** Open places and starting within the next 24 hours. */
    public function isUrgent(CarbonImmutable $now): bool
    {
        return $this->openPlaces() > 0
            && $this->starts_at->greaterThan($now)
            && $this->starts_at->lessThanOrEqualTo($now->addHours(24));
    }

    /** The date and time in the organization's time zone, the US way: "Thursday, October 22, 6:30 to 9:30pm". */
    public function whenText(?string $timezone = null): string
    {
        $timezone ??= Organization::current()->timezone;
        $start = $this->starts_at->setTimezone($timezone);

        return $start->format('l, F j').', '.TimeFormat::range($start, $this->ends_at->setTimezone($timezone));
    }
}
