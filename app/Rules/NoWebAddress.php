<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Text that ends up in an email can't hold something mail apps would turn into a link: "://" or "www." (any capitals). Invisible formatting characters are removed before the check, so they can't be used to split an address up. The email editor, release notes, titles, reasons and names all use this one check.
 */
class NoWebAddress implements ValidationRule
{
    public const MESSAGE = 'This can\'t include a web address.';

    public static function check(string $text): bool
    {
        $text = preg_replace('/\p{Cf}/u', '', $text) ?? $text;

        return stripos($text, '://') !== false || stripos($text, 'www.') !== false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::check($value)) {
            $fail(self::MESSAGE);
        }
    }
}
