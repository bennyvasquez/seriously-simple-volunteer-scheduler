<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One email line the organization edited, keyed like lang/en/emails.php ("login_link.intro"). EmailText uses it in place of the file's default. Rows are written only by App\Actions\Emails\SaveEmailWording, which checks the text and logs the change; a line put back to the default has no row.
 */
class EmailWording extends Model
{
    /** The container key holding this request's (or this queued job's) copy of every edited line. */
    private const CACHE = 'email_wordings.edits';

    protected $fillable = ['key', 'text', 'updated_by'];

    /**
     * Every edited line, keyed by line key, read once per request or queued job. Scoped, so a queue worker reads fresh edits for each job.
     *
     * @return array<string, string>
     */
    public static function edits(): array
    {
        $app = app();

        if (! $app->bound(self::CACHE)) {
            $app->scoped(self::CACHE, fn () => static::query()->pluck('text', 'key')->all());
        }

        return $app->make(self::CACHE);
    }

    /** Drops the cached copy, so the next email reads the table again. Called after every save. */
    public static function forgetCache(): void
    {
        app()->forgetInstance(self::CACHE);
    }

    /**
     * Runs the callback with these edits standing in for the stored ones, then reads the table again afterwards. The editor's preview uses it to show typed text exactly the way an email would, without saving anything.
     *
     * @template T
     *
     * @param  array<string, string>  $edits  Every edited line to use, keyed by line key.
     * @param  callable(): T  $callback
     * @return T
     */
    public static function previewing(array $edits, callable $callback): mixed
    {
        app()->instance(self::CACHE, $edits);

        try {
            return $callback();
        } finally {
            self::forgetCache();
        }
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
