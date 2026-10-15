<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person attached to a weekly slot from a start date to an optional end date. Both dates are included.
 */
class SlotRegular extends Model
{
    protected $fillable = ['weekly_slot_id', 'user_id', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    public function weeklySlot(): BelongsTo
    {
        return $this->belongsTo(WeeklySlot::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function activeOn(string $localDate): bool
    {
        return $this->starts_on->toDateString() <= $localDate
            && ($this->ends_on === null || $localDate <= $this->ends_on->toDateString());
    }
}
