<?php

namespace App\Support;

use App\Rules\NoWebAddress;

/**
 * What a person's name can hold. Names go into emails sent to other people, so a name can't carry link characters or a web address.
 */
class PersonName
{
    public const REFUSAL = 'Names can\'t include web addresses, brackets or control characters.';

    /** Null when the name is fine, otherwise what's wrong with it, in words for the person typing. */
    public static function problem(string $name): ?string
    {
        if (preg_match('/[\[\]<>]|\p{Cc}/u', $name) === 1 || NoWebAddress::check($name)) {
            return self::REFUSAL;
        }

        return null;
    }

    /** The name as stored: invisible formatting and control characters removed, and every run of whitespace (non-breaking spaces too) turned into one space. */
    public static function clean(string $name): string
    {
        $name = preg_replace(['/\p{Cf}/u', '/\s+/u', '/\p{Cc}/u'], ['', ' ', ''], $name) ?? $name;

        return trim($name);
    }
}
