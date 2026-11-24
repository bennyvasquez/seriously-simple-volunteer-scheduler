<?php

namespace App\Notifications;

use App\Models\EmailWording;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use RuntimeException;

/**
 * The wording of every email: the organization's edited line when there is one (App\Models\EmailWording), otherwise the default from lang/en/emails.php. A line holds {variable_name} placeholders (snake_case in braces) that the code fills in. A missing default key, or a placeholder in a default line left unfilled, throws in every environment, so a broken line shows up in tests and never reaches an inbox. Values are put in as given, in one pass, so a value that happens to contain {something} is never filled in a second time.
 *
 * A default line comes back as a plain string, exactly as before edits existed, and Blade escapes it in the email. An edited line comes back as an HtmlString, because someone typed it: its own text is escaped the way PlainText escapes a release note (HTML special characters and Markdown punctuation become entities, whitespace becomes single spaces), so it reads as typed and can't add a link, formatting or HTML. Each value goes through htmlspecialchars, which is what Blade does to a default line, so a value reads the same in an edited line as in a default one. A variable whose name ends in `_link` is built by the code (a Markdown link to a page of ours) and goes in as it is, so an edit can move or drop the link but never change where it points. A value that is already an HtmlString (an edited line passed into another line) goes in as it is too, so nothing is escaped twice; a default line given one comes back as an HtmlString, with its own text escaped the way Blade would have escaped it.
 *
 * Subjects are the exception: a line whose key has a `subject` part ("login_link.subject", "cover_request.subject_still_open", "removed_from_shifts.subject.one") always comes back as a plain string with no entities, and an edited subject on one line, since a subject header is never HTML.
 *
 * A stored edit that can't be used (it names a variable the default line doesn't have, or one this email doesn't fill in, or it lost a link or the {note} the default has) is reported and the default line is sent instead, so a bad edit never stops an email.
 */
final class EmailText
{
    private const VARIABLE = '/\{([a-z][a-z0-9_]*)\}/';

    /** @param array<string, scalar|\Stringable|null> $vars Keyed by variable name, without braces. A variable the line doesn't use is ignored. */
    public static function line(string $key, array $vars = []): string|HtmlString
    {
        $default = self::defaultText($key);
        $edit = self::usableEdit($key, $default, $vars);

        return $edit === null ? self::fillDefault($key, $default, $vars) : self::fillEdit($key, $edit, $vars);
    }

    /**
     * A line that reads differently for one or several: reads "$key.one" when the count is 1, "$key.other" otherwise.
     *
     * @param  array<string, scalar|\Stringable|null>  $vars  Keyed by variable name, without braces. A variable the line doesn't use is ignored.
     */
    public static function choice(string $key, int $count, array $vars = []): string|HtmlString
    {
        return self::line($key.($count === 1 ? '.one' : '.other'), $vars);
    }

    /**
     * A line carrying text someone typed (a release note): the whole line, values included, is escaped with PlainText::line(), the default as before and an edit the same way.
     *
     * @param  array<string, scalar|\Stringable|null>  $vars
     */
    public static function plainTextLine(string $key, array $vars = []): HtmlString
    {
        $default = self::defaultText($key);
        $edit = self::usableEdit($key, $default, $vars);
        $parts = self::parts($edit ?? $default, $key, $vars);
        $text = '';

        foreach ($parts as $i => $part) {
            $text .= $i % 2 === 0 ? $part : self::plain($vars[$part]);
        }

        return new HtmlString(self::escapeStart(PlainText::line($text)->toHtml()));
    }

    /**
     * Joins pieces of one line, such as two sentences. When a piece is an HtmlString (an edited line), the result is one too, with the plain pieces escaped the way Blade would have escaped them; otherwise it's the plain string it always was.
     */
    public static function join(string|HtmlString ...$parts): string|HtmlString
    {
        $html = array_filter($parts, fn ($part) => $part instanceof Htmlable) !== [];

        if (! $html) {
            return implode('', $parts);
        }

        return new HtmlString(implode('', array_map(fn ($part) => $part instanceof Htmlable ? $part->toHtml() : e($part), $parts)));
    }

    /**
     * A Markdown link the code builds, for a variable whose name ends in `_link`: `[text](url)`. The text is a plain string (escaped here for HTML and Markdown, so a name with `]`, `*` or `<` in it reads as typed and can't break out of the link) or an HtmlString that's already escaped (an edited line). The address is the code's own and goes in as it is. It's an HtmlString so the line it's filled into isn't escaped a second time, in the default or in an edit.
     */
    public static function link(string|HtmlString $text, string $url): HtmlString
    {
        return new HtmlString('['.($text instanceof Htmlable ? $text->toHtml() : PlainText::escape($text)).']('.$url.')');
    }

    /** The default wording of one line, from lang/en/emails.php. Throws when there's no such line. */
    public static function defaultText(string $key): string
    {
        $path = 'emails.'.$key;

        if (! Lang::has($path, 'en', false)) {
            throw new InvalidArgumentException("There's no email wording for [{$path}].");
        }

        $line = Lang::get($path, [], 'en');

        if (! is_string($line)) {
            throw new InvalidArgumentException("The email wording [{$path}] isn't a single line.");
        }

        return $line;
    }

    /** Whether the key names one line of lang/en/emails.php (not a whole email, not a one-or-several pair). */
    public static function isLine(string $key): bool
    {
        return Lang::has('emails.'.$key, 'en', false) && is_string(Lang::get('emails.'.$key, [], 'en'));
    }

    /**
     * The variables an edit of this line may use: the ones in its default, in order.
     *
     * @return list<string>
     */
    public static function allowedVariables(string $key): array
    {
        preg_match_all(self::VARIABLE, self::defaultText($key), $matches);

        return array_values(array_unique($matches[1]));
    }

    /**
     * The variables the default line has that an edit must keep: the links the code builds, so nobody removes the only way to a page by accident, and {note}, so a volunteer's own note never silently disappears.
     *
     * @return list<string>
     */
    public static function requiredVariables(string $key): array
    {
        return array_values(array_filter(self::allowedVariables($key), fn (string $name) => str_ends_with($name, '_link') || $name === 'note'));
    }

    /** Whether the line is (part of) a subject, which is never HTML. */
    public static function isSubject(string $key): bool
    {
        foreach (explode('.', $key) as $segment) {
            if ($segment === 'subject' || str_starts_with($segment, 'subject_')) {
                return true;
            }
        }

        return false;
    }

    /** Unicode format characters (bidi overrides, zero-width characters) removed, and whitespace, line breaks included, as single spaces, trimmed: how an edit is stored and sent. */
    public static function normalize(string $text): string
    {
        return trim(preg_replace(['/\p{Cf}/u', '/\s+/u'], ['', ' '], $text));
    }

    /**
     * The stored edit for this line, when there is one this call can fill in. An edit that can't be used is reported and ignored, so the default goes out.
     *
     * @param  array<string, mixed>  $vars
     */
    private static function usableEdit(string $key, string $default, array $vars): ?string
    {
        $edit = EmailWording::edits()[$key] ?? null;

        if ($edit === null) {
            return null;
        }

        $edit = self::normalize($edit);
        preg_match_all(self::VARIABLE, $edit, $matches);
        $allowed = self::allowedVariables($key);
        $problem = null;

        foreach (array_unique($matches[1]) as $name) {
            if (! in_array($name, $allowed, true)) {
                $problem = "uses {{$name}}, which the default line doesn't have";
            } elseif (! array_key_exists($name, $vars)) {
                $problem = "needs {{$name}}, but it wasn't given";
            }
        }

        foreach (self::requiredVariables($key) as $name) {
            if (! in_array($name, $matches[1], true)) {
                $problem = "leaves out {{$name}}, which it has to keep";
            }
        }

        if ($problem !== null) {
            report(new RuntimeException("The edited email wording [emails.{$key}] {$problem}, so the default line was sent instead."));

            return null;
        }

        return $edit;
    }

    /** @param array<string, mixed> $vars */
    private static function fillDefault(string $key, string $line, array $vars): string|HtmlString
    {
        $parts = self::parts($line, $key, $vars);
        $subject = self::isSubject($key);
        $html = false;

        for ($i = 1; $i < count($parts); $i += 2) {
            $html = $html || $vars[$parts[$i]] instanceof Htmlable;
        }

        if ($subject || ! $html) {
            $text = '';
            foreach ($parts as $i => $part) {
                $text .= $i % 2 === 0 ? $part : ($subject ? self::plain($vars[$part]) : (string) $vars[$part]);
            }

            return $text;
        }

        $text = '';
        foreach ($parts as $i => $part) {
            $text .= $i % 2 === 0 ? e($part) : self::html($vars[$part]);
        }

        // Laravel's mail line does this to a plain string; an HtmlString is left alone, so it's done here to keep the line as it read before.
        return new HtmlString(trim(implode(' ', array_map(trim(...), preg_split('/\r\n|\r|\n/', $text)))));
    }

    /** @param array<string, mixed> $vars */
    private static function fillEdit(string $key, string $edit, array $vars): string|HtmlString
    {
        $parts = self::parts($edit, $key, $vars);
        $text = '';

        if (self::isSubject($key)) {
            foreach ($parts as $i => $part) {
                $text .= $i % 2 === 0 ? $part : self::plain($vars[$part]);
            }

            return trim(preg_replace('/[\r\n]+/', ' ', $text));
        }

        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $text .= PlainText::escape($part);
            } else {
                $value = $vars[$part];
                $text .= match (true) {
                    $value instanceof Htmlable => $value->toHtml(),
                    str_ends_with($part, '_link') => (string) $value,
                    default => e((string) $value),
                };
            }
        }

        // The start of the whole line, so an edit that begins with a value that's empty ("{x}- item") is covered too.
        return new HtmlString(self::escapeStart($text));
    }

    /**
     * The line split around its variables: text at even positions, variable names at odd ones. Throws when a variable the line uses wasn't given.
     *
     * @param  array<string, mixed>  $vars
     * @return list<string>
     */
    private static function parts(string $line, string $key, array $vars): array
    {
        $parts = preg_split(self::VARIABLE, $line, -1, PREG_SPLIT_DELIM_CAPTURE);

        for ($i = 1; $i < count($parts); $i += 2) {
            if (! array_key_exists($parts[$i], $vars)) {
                throw new InvalidArgumentException("The email wording [emails.{$key}] needs {{$parts[$i]}}, but it wasn't given.");
            }
        }

        return $parts;
    }

    /** A line that starts like a Markdown list item, rule or heading underline ("- ", "+ ", "1. ", "2) ", "---", "==="), after any spaces, has its first mark made an entity, so it stays a sentence. "#" and ">" are entities already. */
    private static function escapeStart(string $text): string
    {
        return preg_replace(['/^(\s*)-/', '/^(\s*)\+/', '/^(\s*)=/', '/^(\s*\d+)\./', '/^(\s*\d+)\)/'], ['$1&#45;', '$1&#43;', '$1&#61;', '$1&#46;', '$1&#41;'], $text, 1);
    }

    /** A value as HTML for a mail line: an HtmlString as it is, anything else escaped the way Blade escapes it. */
    private static function html(mixed $value): string
    {
        return $value instanceof Htmlable ? $value->toHtml() : e((string) $value);
    }

    /** A value as plain text, for a subject: an HtmlString's entities turned back into characters. */
    private static function plain(mixed $value): string
    {
        return $value instanceof Htmlable ? html_entity_decode($value->toHtml(), ENT_QUOTES | ENT_HTML5, 'UTF-8') : (string) $value;
    }
}
