<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One released place on a shift (spec section 8.3). It's open until someone takes the place, and carries the release note and the escalation ladder's timestamps: resent_at when the request went out again 72 hours before, escalated_at when leads and coordinators got the urgent email.
 */
class CoverRequest extends Model
{
    protected $fillable = ['shift_id', 'released_by', 'note', 'notified_count', 'resent_at', 'escalated_at', 'filled_by', 'filled_at'];

    protected function casts(): array
    {
        return [
            'notified_count' => 'integer',
            'resent_at' => 'immutable_datetime',
            'escalated_at' => 'immutable_datetime',
            'filled_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function releasedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'released_by');
    }

    public function filledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'filled_by');
    }

    /** The releaser as volunteers see them: their first name, or "Someone" once their account has been removed. Used on Home and in the ladder's emails; the take page does the same. */
    public function releaserName(): string
    {
        $releaser = $this->releasedBy;

        return $releaser === null || $releaser->is_removed_person ? 'Someone' : $releaser->firstName();
    }

    /** Requests nobody has taken yet. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('filled_at');
    }
}
