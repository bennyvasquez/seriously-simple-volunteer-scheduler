<?php

namespace App\Notifications;

use App\Models\EmailWording;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use InvalidArgumentException;
use LogicException;
use RuntimeException;

/**
 * The wording of every email: the organization's edited line when there is one (App\Models\EmailWording), otherwise the default from lang/en/emails.php. A line holds {variable_name} placeholders (snake_case in braces) that the code fills in. A missing default key, or a placeholder in a default line left unfilled, throws in every environment, so a broken line shows up in tests and never reaches an inbox. Values are put in as given, in one pass, so a value that happens to contain {something} is never filled in a second time.
 *
 * Every value filled into a line (a person's name, an area name, a title, a reason, the organization's name) is escaped the way PlainText escapes a release note: HTML special characters and Markdown punctuation become entities, so the value reads exactly as typed in both parts of the email and can never become a link, an image or formatting, in a default line or an edited one. A variable whose name ends in `_link` is built by the code (EmailText::link(), a Markdown link to a page of ours) and a `_bold` one by EmailText::bold(); both are HtmlStrings and go in as they are, so an edit can move or drop a link but never change where it points. Any other value that's already an HtmlString (a line passed into another line) goes in as it is too, so nothing is escaped twice.
 *
 * A default line with variables is built as HTML: its own wording is escaped the way Blade would have escaped it (so a default can still use Markdown the code chose), and its values as above. It comes back as an HtmlString, except when its values needed nothing beyond HTML escaping (no Markdown punctuation, no line breaks, nothing to change at the start of the line): then Blade escaping the plain line is just as safe, and it comes back as the plain string it always was. A default line with no variables is always a plain string, and Blade escapes it in the email. An edited line always comes back as an HtmlString, because someone typed it: its own text is escaped the way PlainText escapes a release note, so it reads as typed and can't add a link, formatting or HTML. Either way the line is put on one line, and a line that would start like a Markdown list, rule or heading has that first mark made an entity, so a value at the start of a line stays a sentence.
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

        return PlainText::line($text);
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

    /** Bold text the code builds, for a variable whose name ends in `_bold`: `**text**`, with the text escaped for HTML and Markdown (or an HtmlString that's already escaped). */
    public static function bold(string|HtmlString $text): HtmlString
    {
        return new HtmlString('**'.($text instanceof Htmlable ? $text->toHtml() : PlainText::escape($text)).'**');
    }

    /**
     * A Markdown link the code builds, for a variable whose name ends in `_link`: `[text](url)`. The text is a plain string (escaped here for HTML and Markdown, so a name with `]`, `*` or `<` in it reads as typed and can't break out of the link) or an HtmlString that's already escaped (an edited line). The address is the code's own and goes in as it is; one that doesn't start with http:// or https:// throws, and so does a `_link` variable that isn't from here (a plain string) in an edited line. It's an HtmlString so the line it's filled into isn't escaped a second time, in the default or in an edit.
     */
    public static function link(string|HtmlString $text, string $url): HtmlString
    {
        if (! preg_match('#^https?://#i', $url)) {
            throw new LogicException('A link in an email has to start with http:// or https://.');
        }

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

        if (self::isSubject($key) || count($parts) === 1) {
            $text = '';
            foreach ($parts as $i => $part) {
                $text .= $i % 2 === 0 ? $part : self::plain($vars[$part]);
            }

            return $text;
        }

        $text = '';
        $htmlOnly = '';
        $plain = '';
        $html = false;
        foreach ($parts as $i => $part) {
            if ($i % 2 === 0) {
                $text .= e($part);
                $htmlOnly .= e($part);
                $plain .= $part;
            } else {
                $value = $vars[$part];
                $html = $html || $value instanceof Htmlable;
                $text .= self::html($value);
                $htmlOnly .= htmlspecialchars((string) $value, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $plain .= (string) $value;
            }
        }
        $text = PlainText::escapeStart(self::oneLine($text));

        // When the values needed nothing beyond HTML escaping (no Markdown punctuation, no line breaks, nothing at the start of the line), Blade escaping the plain line is just as safe, so it stays the plain string it always was.
        return ! $html && $text === $htmlOnly ? $plain : new HtmlString($text);
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
                    str_ends_with($part, '_link') => throw new LogicException("The link variable {{$part}} has to come from EmailText::link(), so an edited line can't carry HTML."),
                    default => PlainText::escape((string) $value),
                };
            }
        }

        // The start of the whole line, so an edit that begins with a value that's empty ("{x}- item") is covered too.
        return new HtmlString(PlainText::escapeStart(self::oneLine($text)));
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

    /** The line's line breaks made spaces, each piece trimmed, the way Laravel's mail line treats a plain string (it leaves an HtmlString alone), so a value with a line break in it can't start a new paragraph, list or heading. */
    private static function oneLine(string $text): string
    {
        return trim(implode(' ', array_map(trim(...), preg_split('/\r\n|\r|\n/', $text))));
    }

    /** A value as HTML for a mail line: an HtmlString as it is, anything else escaped for HTML and Markdown, so it reads as typed. */
    private static function html(mixed $value): string
    {
        return $value instanceof Htmlable ? $value->toHtml() : PlainText::escape((string) $value);
    }

    /** A value as plain text, for a subject: an HtmlString's entities turned back into characters. */
    private static function plain(mixed $value): string
    {
        return $value instanceof Htmlable ? html_entity_decode($value->toHtml(), ENT_QUOTES | ENT_HTML5, 'UTF-8') : (string) $value;
    }
}
