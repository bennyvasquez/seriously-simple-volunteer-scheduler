<?php

namespace App\Support;

/**
 * One spelling per email address. Addresses are plain printable ASCII, lowercased and trimmed wherever one is typed, so the database, the sign-in limits and the emails we send all see the same text for the same address.
 */
class EmailAddress
{
    /** What a person sees when the address they typed has characters other than plain letters, numbers and symbols. It's the same for every address, so it reveals nothing about accounts. */
    public const REFUSAL = 'Type the address using only plain letters, numbers and symbols.';

    public static function normalize(string $address): string
    {
        return mb_strtolower(trim($address));
    }

    /** True when every byte is a printable ASCII character (no control characters, nothing past 126). A space counts as printable here, and the usual email rule refuses it. */
    public static function isPlain(string $address): bool
    {
        return preg_match('/\A[\x20-\x7E]*\z/', $address) === 1;
    }
}
