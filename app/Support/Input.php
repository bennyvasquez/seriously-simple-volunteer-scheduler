<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Http\Request;

/**
 * Reads what a visitor sent without trusting its shape. A form field or address value can arrive as a list ("q[]=x") or as a date that doesn't exist ("2026-99-99"); casting those throws, which shows an error page, so each page reads them through here and falls back to what it does for a missing value.
 */
class Input
{
    /** The request's value for $key (from the address or the form, the way $request->input() reads). A key that isn't there gives $default. A key that is there gives its text, or '' when it was sent empty, so a field left empty stays empty instead of being filled in. A list gives $default. */
    public static function string(Request $request, string $key, string $default = ''): string
    {
        return $request->exists($key) ? self::text($request->input($key), $default) : $default;
    }

    /** The text of a form field or remembered form input: a string as it is, a number as text, and nothing (null, as an empty field is read) as ''. Anything else, a list or a boolean, gives $default. */
    public static function text(mixed $value, string $default = ''): string
    {
        return match (true) {
            is_string($value) => $value,
            is_int($value), is_float($value) => (string) $value,
            $value === null => '',
            default => $default,
        };
    }

    /** A real calendar date written Y-m-d, or null for anything else (a list, a date that doesn't exist, another format). */
    public static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value, 'UTC');
        } catch (InvalidFormatException) {
            return null;
        }

        return $date !== false && $date->format('Y-m-d') === $value ? $date : null;
    }
}
