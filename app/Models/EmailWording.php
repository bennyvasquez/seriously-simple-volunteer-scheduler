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

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
