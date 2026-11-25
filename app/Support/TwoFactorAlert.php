<?php

namespace App\Support;

use App\Models\User;
use App\Notifications\TwoFactorAttemptsNotification;
use Illuminate\Support\Facades\Cache;

/**
 * Tells a person, at most once a day, that a lot of wrong two-factor codes have been typed on their account. Two things trigger it and share the once-a-day flag: ten wrong codes in a day, and the hourly or daily code limit stopping a request. There's no change log entry: failed sign-in steps aren't logged (spec decision log, 2026-10-17), and the email is the signal.
 */
class TwoFactorAlert
{
    /** How many wrong codes in a day make the email go out. */
    public const WRONG_CODES = 10;

    /** Counts one wrong code for the person with this pending login id, and sends the email when the count reaches the number above. Does nothing without a pending person. */
    public static function wrongCode(mixed $id): void
    {
        if ($id === null || User::query()->find($id) === null) {
            return;
        }

        $key = 'two-factor-wrong|'.$id;

        // The day starts at the first wrong code, so the count can't be kept alive by later ones.
        Cache::add($key, 0, now()->addDay());

        if (Cache::increment($key) >= self::WRONG_CODES) {
            self::send($id);
        }
    }

    /** Sends the email unless one already went out today. It goes after the response, and still goes when the response is an error such as a 429. */
    public static function send(mixed $id): void
    {
        if ($id === null || User::query()->find($id) === null || ! Cache::add('two-factor-alerted|'.$id, true, now()->addDay())) {
            return;
        }

        defer(fn () => User::query()->find($id)?->notify(new TwoFactorAttemptsNotification), always: true);
    }
}
