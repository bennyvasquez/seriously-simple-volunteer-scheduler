<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * Text that ends up in an email can't hold something mail apps would turn into a link: "://" or "www." (any capitals). Before the check, invisible formatting characters and the blank Hangul filler characters (U+115F, U+1160, U+3164, U+FFA0) are removed so they can't be used to split an address up, the text is folded to its plain compatibility form (Unicode NFKC, so full-width ":", "/" and "." become plain ones), and the ideographic full stop (U+3002) counts as ".". The email editor, release notes, titles, reasons and names all use this one check.
 */
class NoWebAddress implements ValidationRule
{
    public const MESSAGE = 'This can\'t include a web address.';

    public static function check(string $text): bool
    {
        $text = preg_replace('/\p{Cf}|[\x{115F}\x{1160}\x{3164}\x{FFA0}]/u', '', $text) ?? $text;
        $text = Normalizer::normalize($text, Normalizer::FORM_KC) ?: $text;
        $text = str_replace("\u{3002}", '.', $text);

        return stripos($text, '://') !== false || stripos($text, 'www.') !== false;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && self::check($value)) {
            $fail(self::MESSAGE);
        }
    }
}
