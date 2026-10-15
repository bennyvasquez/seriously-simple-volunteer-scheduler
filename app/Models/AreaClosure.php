<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * A date range when an area is closed, such as a holiday. Both dates are included. Named AreaClosure so it doesn't collide with PHP's built-in Closure class.
 */
class AreaClosure extends Model
{
    protected $fillable = ['area_id', 'starts_on', 'ends_on', 'reason'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (AreaClosure $closure) {
            if ($closure->ends_on->lt($closure->starts_on)) {
                throw new InvalidArgumentException('A closure has to end on or after the day it starts.');
            }
        });
    }

    public function area(): BelongsTo
    {
        return $this->belongsTo(Area::class);
    }

    public function covers(string $localDate): bool
    {
        return $this->starts_on->toDateString() <= $localDate
            && $localDate <= $this->ends_on->toDateString();
    }
}
