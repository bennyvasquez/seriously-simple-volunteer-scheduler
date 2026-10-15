<?php

namespace App\Models;

use App\Enums\AssignmentType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A person on a specific shift. Shadow assignments never count toward the shift's headcount.
 */
class Assignment extends Model
{
    protected $fillable = ['shift_id', 'user_id', 'type'];

    protected function casts(): array
    {
        return ['type' => AssignmentType::class];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
