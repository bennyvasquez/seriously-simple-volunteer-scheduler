<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Who did what to whom, and when (spec section 13). Entries are never edited or deleted: the model throws if you try. The one exception is forgetPersonalDetailsOf(), which scrubs a removed person's name and contact details through the query builder.
 */
class ChangeLogEntry extends Model
{
    public const UPDATED_AT = null;

    /** Shown in place of the name of a person whose account was removed. */
    public const REMOVED_PERSON = 'Removed person';

    protected $table = 'change_log';

    protected $fillable = ['actor_id', 'actor_name', 'action', 'subject_type', 'subject_id', 'affected_user_id', 'details', 'changes'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Change log entries are never edited.'));
        static::deleting(fn () => throw new LogicException('Change log entries are never edited.'));
    }

    protected function casts(): array
    {
        return ['details' => 'array', 'changes' => 'array'];
    }

    /** $about is the person the entry is about; it defaults to the subject when the subject is a user. */
    public static function record(?User $actor, string $action, Model $subject, array $details = [], array $changes = [], ?User $about = null): self
    {
        $about ??= $subject instanceof User ? $subject : null;

        return static::create([
            'actor_id' => $actor?->id,
            'actor_name' => $actor?->name,
            'action' => $action,
            'subject_type' => $subject->getMorphClass(),
            'subject_id' => $subject->getKey(),
            'affected_user_id' => $about?->id,
            'details' => $details === [] ? null : $details,
            'changes' => $changes === [] ? null : $changes,
        ]);
    }

    /** Old and new values of every dirty attribute. Call before saving. Hidden attributes (password hashes, tokens, secrets) are logged as changed, without values. */
    public static function changesOf(Model $model): array
    {
        $changes = [];
        $hidden = $model->getHidden();

        foreach (array_keys($model->getDirty()) as $key) {
            if (in_array($key, ['created_at', 'updated_at'], true)) {
                continue;
            }

            $changes[$key] = in_array($key, $hidden, true)
                ? ['hidden' => true]
                : ['old' => $model->getRawOriginal($key), 'new' => $model->getAttributes()[$key]];
        }

        return $changes;
    }

    /** Scrubs a person's name and contact details from every entry they made or that is about them. Returns how many entries changed. Writes with the query builder, the one path that skips the edit guard. */
    public static function forgetPersonalDetailsOf(User $user): int
    {
        return DB::transaction(function () use ($user) {
            $touched = [];

            $byUser = DB::table('change_log')->where('actor_id', $user->id)->pluck('id');
            DB::table('change_log')->whereIn('id', $byUser)->update(['actor_name' => self::REMOVED_PERSON]);
            foreach ($byUser as $id) {
                $touched[$id] = true;
            }

            $about = DB::table('change_log')
                ->where(fn ($query) => $query
                    ->where('affected_user_id', $user->id)
                    ->orWhere(fn ($query) => $query->where('subject_type', $user->getMorphClass())->where('subject_id', $user->id)))
                ->whereNotNull('changes')
                ->get(['id', 'changes']);

            foreach ($about as $row) {
                $changes = json_decode($row->changes, true);
                $scrubbed = false;

                foreach (User::PERSONAL_FIELDS as $field) {
                    if (isset($changes[$field])) {
                        $changes[$field] = ['old' => null, 'new' => null, 'removed' => true];
                        $scrubbed = true;
                    }
                }

                if ($scrubbed) {
                    DB::table('change_log')->where('id', $row->id)->update(['changes' => json_encode($changes)]);
                    $touched[$row->id] = true;
                }
            }

            return count($touched);
        });
    }

    public function isBySystem(): bool
    {
        return $this->actor_id === null && $this->actor_name === null;
    }

    public function scopeByActor(Builder $query, User $user): void
    {
        $query->where('actor_id', $user->id);
    }

    public function scopeAbout(Builder $query, User $user): void
    {
        $query->where('affected_user_id', $user->id);
    }

    public function scopeForSubject(Builder $query, Model $subject): void
    {
        $query->where('subject_type', $subject->getMorphClass())->where('subject_id', $subject->getKey());
    }

    /** An action ending in ".*" matches everything with that prefix, for example "role.*". */
    public function scopeOfAction(Builder $query, string ...$actions): void
    {
        $query->where(function (Builder $query) use ($actions) {
            foreach ($actions as $action) {
                if (str_ends_with($action, '.*')) {
                    $query->orWhere('action', 'like', addcslashes(substr($action, 0, -1), '%_\\').'%');
                } else {
                    $query->orWhere('action', $action);
                }
            }
        });
    }

    /** Inclusive; either end can be left out. */
    public function scopeBetween(Builder $query, ?CarbonInterface $from, ?CarbonInterface $to): void
    {
        $query->when($from, fn ($query) => $query->where('created_at', '>=', $from))
            ->when($to, fn ($query) => $query->where('created_at', '<=', $to));
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function affectedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'affected_user_id');
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }
}
