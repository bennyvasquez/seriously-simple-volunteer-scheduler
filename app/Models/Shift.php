<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'local_date' => 'immutable_date',
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'headcount' => 'integer',
            'shadow_spots' => 'integer',
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

    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }
}
