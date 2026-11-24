<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's notice that the time of one shift changed, with the time it had before. Rows made by one edit for one person share a batch (one email, one answer). It's open until the person confirms it (confirmed_at), or until a newer time change on the shift or the person leaving the shift ends it (ended_at). lead_told_at is set when the area's leads were told the person never answered.
 */
class ChangeNotice extends Model
{
    protected $fillable = ['batch', 'user_id', 'shift_id', 'changed_by', 'was_starts_at', 'was_ends_at', 'confirmed_at', 'ended_at', 'lead_told_at'];

    protected function casts(): array
    {
        return [
            'was_starts_at' => 'immutable_datetime',
            'was_ends_at' => 'immutable_datetime',
            'confirmed_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'lead_told_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /** Notices not confirmed and not ended. */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('change_notices.confirmed_at')->whereNull('change_notices.ended_at');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    /**
     * Every row of one person's batch, earliest shift first. The query is scoped to the person before it runs, so someone else's batch costs what a made-up one does and loads none of their rows.
     *
     * @return Collection<int, static>
     */
    public static function batchFor(string $batch, User $user): Collection
    {
        return static::query()->with(['shift.area', 'changedBy'])->where('change_notices.batch', $batch)->where('change_notices.user_id', $user->id)
            ->join('shifts', 'shifts.id', '=', 'change_notices.shift_id')
            ->orderBy('shifts.starts_at')->orderBy('change_notices.id')
            ->select('change_notices.*')->get();
    }

    /**
     * The person's open notices on shifts that are still ahead, not cancelled and that they're still on, earliest shift first, with the shift, its area and who changed it loaded. One query. Home, the shift panel and the shift cards ask with this.
     *
     * @return Collection<int, static>
     */
    public static function openFor(User $user): Collection
    {
        return static::query()->with(['shift.area', 'changedBy'])->open()
            ->where('change_notices.user_id', $user->id)
            ->join('shifts', 'shifts.id', '=', 'change_notices.shift_id')
            ->whereNull('shifts.cancelled_at')->where('shifts.starts_at', '>', now())
            ->whereExists(fn ($assignments) => $assignments->selectRaw('1')->from('assignments')
                ->whereColumn('assignments.shift_id', 'change_notices.shift_id')->where('assignments.user_id', $user->id))
            ->orderBy('shifts.starts_at')->orderBy('change_notices.id')
            ->select('change_notices.*')->get();
    }
}
