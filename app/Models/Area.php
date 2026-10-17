<?php

namespace App\Models;

use App\Enums\AreaColor;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A place or kind of work that needs staff, such as Front desk. Every shift belongs to one area.
 */
class Area extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'color'];

    protected function casts(): array
    {
        return ['color' => AreaColor::class];
    }
}
