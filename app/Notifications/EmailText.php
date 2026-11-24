<?php

namespace App\Notifications;

use Illuminate\Support\Facades\Lang;
use InvalidArgumentException;

/**
 * The wording of every email, read from lang/en/emails.php. A line holds {variable_name} placeholders (snake_case in braces) that the code fills in. A missing key or a placeholder left unfilled throws in every environment, so a broken line shows up in tests and never reaches an inbox. Values are put in as given, in one pass, so a value that happens to contain {something} is never filled in a second time. Escaping typed text stays the caller's job.
 */
final class EmailText
{
    /** @param array<string, scalar|\Stringable|null> $vars Keyed by variable name, without braces. A variable the line doesn't use is ignored. */
    public static function line(string $key, array $vars = []): string
    {
        $path = 'emails.'.$key;

        if (! Lang::has($path, 'en', false)) {
            throw new InvalidArgumentException("There's no email wording for [{$path}].");
        }

        $line = Lang::get($path, [], 'en');

        if (! is_string($line)) {
            throw new InvalidArgumentException("The email wording [{$path}] isn't a single line.");
        }

        return preg_replace_callback('/\{([a-z][a-z0-9_]*)\}/', function (array $match) use ($vars, $path): string {
            if (! array_key_exists($match[1], $vars)) {
                throw new InvalidArgumentException("The email wording [{$path}] needs {{$match[1]}}, but it wasn't given.");
            }

            return (string) $vars[$match[1]];
        }, $line);
    }

    /** A line that reads differently for one or several: reads "$key.one" when the count is 1, "$key.other" otherwise. */
    public static function choice(string $key, int $count, array $vars = []): string
    {
        return self::line($key.($count === 1 ? '.one' : '.other'), $vars);
    }
}
