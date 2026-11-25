<?php

namespace App\Emails;

use App\Models\EmailWording;
use App\Notifications\EmailText;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Mail\Markdown;
use Illuminate\Support\HtmlString;

/**
 * One version of an email as the editor's preview shows it (wireframe 63): the catalog's example people and the given edits in place of the stored ones, with nothing saved or sent. Each line goes through EmailText exactly as the notification sends it, so typed text is escaped the same way, and the rest is turned into HTML the way Laravel's mail template does it: a plain line is escaped (Blade's `{{ }}`), an escaped one is kept, and Markdown turns the result into HTML, so the only links are the ones the code builds. Links point at example.org.
 *
 * A catalog line can have its own example values (the reminder's shadows are Casey M., not the Jordan P. who's on the shift); they're used for that line only, and a version's examples still win.
 *
 * Phrases used inside other lines (an `inside` catalog line) fill in the variable they stand for: `x_label` is the text of the `{x_link}` link, `shift_phrase` is `{shift}`, and any other phrase (`how.every_week`, `shift_line`) is the variable named like it. When a version lists both lines of a one-or-several pair, the one its example `{count}` picks is shown. Lines that fill in part of another line (`needs`, `released` and `emailed` in the urgent email) go into that line the way the code puts them there, or make one line together when no line shown uses them.
 */
final class EmailPreview
{
    public const URL = 'https://example.org/';

    /**
     * @param  array<string, string>  $edits  Every edited line to use, keyed by line key.
     * @return array{subject: ?string, blocks: list<array{kind: string, html: HtmlString}>}
     */
    public static function render(CatalogEmail $email, string $version, array $edits): array
    {
        return EmailWording::previewing($edits, function () use ($email, $version) {
            $chosen = $email->versions[$version];
            $vars = self::variables($email, $chosen['examples']);
            $keys = self::pick($chosen['lines'], $vars['count'] ?? null);
            $groups = self::fragments($email, $keys);
            $used = [];

            foreach ($keys as $key) {
                if (! isset($groups[$key]) && ! self::inGroup($groups, $key)) {
                    array_push($used, ...EmailText::allowedVariables($key));
                }
            }

            // A fragment that fills in a word another line uses goes in there, not on its own.
            foreach ($groups as $group) {
                if (in_array($group['variable'], $used, true)) {
                    $vars[$group['variable']] = self::joined($group['keys'], $vars);
                }
            }

            $subject = null;
            $blocks = [];

            foreach ($keys as $key) {
                if (EmailText::isSubject($key)) {
                    $subject = (string) EmailText::line($key, self::lineVariables($email, $key, $vars, $chosen['examples']));

                    continue;
                }

                if (isset($groups[$key])) {
                    if (! in_array($groups[$key]['variable'], $used, true)) {
                        $blocks[] = self::block('line', self::joined($groups[$key]['keys'], $vars));
                    }

                    continue;
                }

                if (self::inGroup($groups, $key)) {
                    continue;
                }

                $lineVars = self::lineVariables($email, $key, $vars, $chosen['examples']);
                $line = in_array('note', EmailText::allowedVariables($key), true) ? EmailText::plainTextLine($key, $lineVars) : EmailText::line($key, $lineVars);
                $blocks[] = self::block(self::kind($email, $key), $line);
            }

            return ['subject' => $subject, 'blocks' => $blocks];
        });
    }

    /** @return array{kind: string, html: HtmlString} */
    private static function block(string $kind, string|HtmlString $line): array
    {
        $source = $line instanceof Htmlable ? $line->toHtml() : e($line);

        return ['kind' => $kind, 'html' => new HtmlString($kind === 'line' ? Markdown::parse($source)->toHtml() : $source)];
    }

    /**
     * Lines that fill in part of another line (the catalog says "Fills in" when they show), grouped the way the code joins them: one whose name is a word some line of the email uses, such as `needs` or `released`, starts a group for that word, and the ones after it that aren't (`emailed`) are added to it, with a space between. Keyed by the group's first line.
     *
     * @param  list<string>  $keys
     * @return array<string, array{variable: string, keys: list<string>}>
     */
    private static function fragments(CatalogEmail $email, array $keys): array
    {
        $words = [];
        foreach ($email->lineKeys() as $key) {
            if (EmailText::isLine($key)) {
                array_push($words, ...EmailText::allowedVariables($key));
            }
        }

        $groups = [];
        $current = null;

        foreach ($keys as $key) {
            $line = collect($email->lines)->firstWhere('key', $key);

            if ($line === null || ! str_starts_with((string) $line['when'], 'Fills in')) {
                $current = null;

                continue;
            }

            $name = explode('.', substr($key, strlen($email->key) + 1))[0];

            if (in_array($name, $words, true) || $current === null) {
                $current = $key;
                $groups[$key] = ['variable' => $name, 'keys' => [$key]];
            } else {
                $groups[$current]['keys'][] = $key;
            }
        }

        return $groups;
    }

    /** @param array<string, array{variable: string, keys: list<string>}> $groups */
    private static function inGroup(array $groups, string $key): bool
    {
        foreach ($groups as $first => $group) {
            if ($first !== $key && in_array($key, $group['keys'], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, mixed>  $vars
     */
    private static function joined(array $keys, array $vars): string|HtmlString
    {
        $parts = [];
        foreach ($keys as $i => $key) {
            if ($i > 0) {
                $parts[] = ' ';
            }
            $parts[] = EmailText::line($key, $vars);
        }

        return EmailText::join(...$parts);
    }

    /**
     * The example value of every variable the email's lines use, with the phrases used inside other lines filled in from their current wording, so an edit to one shows where it's used. A version's own examples win. Link variables are Markdown links to example.org, and a `_bold` variable is bold.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string|HtmlString>
     */
    public static function variables(CatalogEmail $email, array $overrides = []): array
    {
        $vars = self::texts($email, $overrides);

        foreach ($vars as $name => $value) {
            $vars[$name] = self::built($name, $value);
        }

        return $vars;
    }

    /** One example value built the way the notifications build it: a link to a page, and bold text in Markdown. */
    private static function built(string $name, string|HtmlString $value): string|HtmlString
    {
        return match (true) {
            str_ends_with($name, '_link') => EmailText::link($value, self::URL),
            str_ends_with($name, '_bold') => '**'.$value.'**',
            default => $value,
        };
    }

    /**
     * The values one line is filled in with: the email's, with the line's own examples over them (the reminder's shadows aren't the people on the shift), except where the version sets the variable itself.
     *
     * @param  array<string, string|HtmlString>  $vars
     * @param  array<string, string>  $versionExamples
     * @return array<string, string|HtmlString>
     */
    private static function lineVariables(CatalogEmail $email, string $key, array $vars, array $versionExamples): array
    {
        foreach (collect($email->lines)->firstWhere('key', $key)['examples'] ?? [] as $name => $value) {
            if (! array_key_exists($name, $versionExamples)) {
                $vars[$name] = self::built($name, $value);
            }
        }

        return $vars;
    }

    /**
     * What the "Words you can use in this email" list shows for each variable: its example as plain text, and for a link, the words it reads.
     *
     * @return array<string, string>
     */
    public static function words(CatalogEmail $email): array
    {
        $words = [];

        foreach (self::texts($email) as $name => $value) {
            $text = $value instanceof Htmlable ? html_entity_decode($value->toHtml(), ENT_QUOTES | ENT_HTML5, 'UTF-8') : (string) $value;
            $words[$name] = str_ends_with($name, '_link') ? 'a link reading “'.$text.'”' : $text;
        }

        return $words;
    }

    /**
     * Every variable's example, with inside phrases filled in, and a link variable as the words it reads.
     *
     * @param  array<string, string>  $overrides
     * @return array<string, string|HtmlString>
     */
    private static function texts(CatalogEmail $email, array $overrides = []): array
    {
        $vars = array_merge($email->examples, $overrides);
        $done = [];

        foreach ($email->lines as $line) {
            $name = self::insideVariable($email->key, $line);

            if ($name === null || array_key_exists($name, $overrides) || isset($done[$name]) || ! EmailText::isLine($line['key'])) {
                continue;
            }

            $done[$name] = true;
            $vars[$name] = EmailText::line($line['key'], $vars);
        }

        return $vars;
    }

    /** @param array{key: string, label: string, when: ?string, inside: bool} $line */
    private static function insideVariable(string $email, array $line): ?string
    {
        if (! $line['inside']) {
            return null;
        }

        $name = explode('.', substr($line['key'], strlen($email) + 1))[0];

        return match (true) {
            $name === 'shift_phrase' => 'shift',
            str_ends_with($name, '_label') => substr($name, 0, -strlen('_label')).'_link',
            default => $name,
        };
    }

    /**
     * The version's lines with one of each one-or-several pair, picked by the example count.
     *
     * @param  list<string>  $keys
     * @return list<string>
     */
    private static function pick(array $keys, mixed $count): array
    {
        $drop = (string) $count === '1' ? '.other' : '.one';
        $keep = $drop === '.one' ? '.other' : '.one';

        return array_values(array_filter($keys, function (string $key) use ($keys, $drop, $keep) {
            if (! str_ends_with($key, $drop)) {
                return true;
            }

            return ! in_array(substr($key, 0, -strlen($drop)).$keep, $keys, true);
        }));
    }

    /** "greeting", "button" or "line". The greeting is a heading in the email; here it's a bold line, so the page keeps its one h1. */
    private static function kind(CatalogEmail $email, string $key): string
    {
        $label = collect($email->lines)->firstWhere('key', $key)['label'] ?? '';

        return match (true) {
            str_contains($key, '.greeting') => 'greeting',
            preg_match('/^((first|second) )?button\b/i', $label) === 1 => 'button',
            default => 'line',
        };
    }
}
