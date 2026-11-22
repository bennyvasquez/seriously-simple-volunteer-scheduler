<?php

namespace App\Notifications;

use Illuminate\Support\HtmlString;

/**
 * Free text someone typed (a release note), made safe for a mail line. The HTML part of an email runs its lines through Markdown and the plain-text part doesn't, so backslash escapes would show in the text part. Instead, HTML special characters and Markdown punctuation become HTML entities: Markdown never treats an entity as syntax, so the HTML part shows the characters as typed, and Laravel decodes entities in the text part, so it shows the note as written. Whitespace, line breaks included, becomes single spaces, so nothing in the text starts a heading or a list.
 */
final class PlainText
{
    private const MARKDOWN = ['\\' => '&#92;', '`' => '&#96;', '*' => '&#42;', '_' => '&#95;', '[' => '&#91;', ']' => '&#93;', '!' => '&#33;', '#' => '&#35;', '|' => '&#124;', '~' => '&#126;'];

    /** A whole mail line, already escaped, so Blade doesn't escape it a second time. */
    public static function line(string $text): HtmlString
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text));

        return new HtmlString(strtr(htmlspecialchars($text, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8'), self::MARKDOWN));
    }
}
