<?php

namespace App\Models;

use App\Enums\AreaColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A place or kind of work that needs staff, such as Front desk. Every shift belongs to one area.
 */
class Area extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'color', 'lead_name'];

    protected function casts(): array
    {
        return ['color' => AreaColor::class];
    }

    /** What this area calls its leads: the name set on the area, or "Area lead". */
    public function leadTitle(): string
    {
        return $this->lead_name ?: 'Area lead';
    }

    public function weeklySlots(): HasMany
    {
        return $this->hasMany(WeeklySlot::class);
    }
}
