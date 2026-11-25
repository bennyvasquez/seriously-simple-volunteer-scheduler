<?php

namespace App\Support;

use App\Rules\NoWebAddress;

/**
 * What a person's name can hold. Names go into emails sent to other people, so a name can't carry link characters or a web address.
 */
class PersonName
{
    public const REFUSAL = 'Names can\'t include web addresses or the characters [ ] < >.';

    /** Null when the name, as clean() would store it, is fine; otherwise what's wrong with it, in words for the person typing. Stray tabs, newlines and control characters aren't a problem: clean() tidies them. */
    public static function problem(string $name): ?string
    {
        $name = self::clean($name);

        if (preg_match('/[\[\]<>]/u', $name) === 1 || NoWebAddress::check($name)) {
            return self::REFUSAL;
        }

        return null;
    }

    /** The name as stored: tabs and line breaks become spaces, control characters and invisible formatting characters are removed (except U+200C and U+200D, the zero-width non-joiner and joiner that some languages' names and emoji need), and every run of whitespace (non-breaking spaces too) becomes one space. */
    public static function clean(string $name): string
    {
        $name = preg_replace('/[\t\n\r]/u', ' ', $name) ?? $name;
        $name = preg_replace('/\p{Cc}|(?![\x{200C}\x{200D}])\p{Cf}/u', '', $name) ?? $name;
        $name = preg_replace('/\s+/u', ' ', $name) ?? $name;

        return trim($name);
    }
}
