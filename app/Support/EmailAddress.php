<?php

namespace App\Support;

/**
 * One spelling per email address. Addresses are plain printable ASCII, lowercased and trimmed (spaces and invisible characters at both ends) wherever one is typed, so the database, the sign-in limits and the emails we send all see the same text for the same address.
 */
class EmailAddress
{
    /** What a person sees when the address they typed has characters other than plain letters, numbers and symbols. It's the same for every address, so it reveals nothing about accounts. */
    public const REFUSAL = 'Type the address using only plain letters, numbers and symbols.';

    public static function normalize(string $address): string
    {
        // Pasted addresses often carry a non-breaking space or a zero-width character at an end. Those go; the same characters inside the address stay, so isPlain() still refuses them.
        $trimmed = preg_replace('/\A[\s\p{Z}\p{Cf}]+|[\s\p{Z}\p{Cf}]+\z/u', '', $address);

        return mb_strtolower($trimmed ?? trim($address));
    }

    /** True when every byte is a printable ASCII character (no control characters, nothing past 126). A space counts as printable here, and the usual email rule refuses it. */
    public static function isPlain(string $address): bool
    {
        return preg_match('/\A[\x20-\x7E]*\z/', $address) === 1;
    }
}
