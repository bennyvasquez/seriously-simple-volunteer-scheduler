<?php

namespace App\Notifications;

use Illuminate\Support\HtmlString;

/**
 * Text someone typed (a release note, a name, an area name, a title, a reason, the organization's name), made safe for a mail line. The HTML part of an email runs its lines through Markdown and the plain-text part doesn't, so backslash escapes would show in the text part. Instead, HTML special characters and Markdown punctuation become HTML entities: Markdown never treats an entity as syntax, so the HTML part shows the characters as typed, and Laravel decodes entities in the text part, so it shows the text as written. Quotes are left as they are (O'Brien stays O'Brien), since a mail line is never inside an HTML attribute. A whole line has its whitespace, line breaks included, made single spaces, and a start that Markdown would read as a list, rule or heading made an entity, so it stays a sentence.
 */
final class PlainText
{
    private const MARKDOWN = ['\\' => '&#92;', '`' => '&#96;', '*' => '&#42;', '_' => '&#95;', '[' => '&#91;', ']' => '&#93;', '!' => '&#33;', '#' => '&#35;', '|' => '&#124;', '~' => '&#126;'];

    /** A whole mail line, already escaped, so Blade doesn't escape it a second time. */
    public static function line(string $text): HtmlString
    {
        return new HtmlString(self::escapeStart(self::escape(trim(preg_replace('/\s+/u', ' ', $text)))));
    }

    /** The same escaping without touching whitespace, for a piece of a line (EmailText escapes an edited line's own text and every value it fills in this way). */
    public static function escape(string $text): string
    {
        return strtr(htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), self::MARKDOWN);
    }

    /** A line, already escaped, that starts like a Markdown list item, rule or heading underline ("- ", "+ ", "1. ", "2) ", "---", "==="), after any spaces, has its first mark made an entity, so it stays a sentence. "#" and ">" are entities already. */
    public static function escapeStart(string $html): string
    {
        return preg_replace(['/^(\s*)-/', '/^(\s*)\+/', '/^(\s*)=/', '/^(\s*\d+)\./', '/^(\s*\d+)\)/'], ['$1&#45;', '$1&#43;', '$1&#61;', '$1&#46;', '$1&#41;'], $html, 1);
    }
}
