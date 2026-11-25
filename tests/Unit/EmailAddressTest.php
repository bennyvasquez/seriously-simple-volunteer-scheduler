<?php

namespace Tests\Unit;

use App\Support\EmailAddress;
use PHPUnit\Framework\TestCase;

class EmailAddressTest extends TestCase
{
    public function test_an_address_is_trimmed_and_lowercased(): void
    {
        $this->assertSame('victim@example.org', EmailAddress::normalize("  Victim@Example.ORG\n"));
    }

    public function test_plain_addresses_are_plain(): void
    {
        $this->assertTrue(EmailAddress::isPlain('first.last+tag@example.org'));
    }

    public function test_accents_zero_width_characters_and_control_characters_are_not_plain(): void
    {
        $this->assertFalse(EmailAddress::isPlain('vïctim@example.org'));
        $this->assertFalse(EmailAddress::isPlain("vi\u{200B}ctim@example.org"));
        $this->assertFalse(EmailAddress::isPlain("victim@example.org\n"));
        $this->assertFalse(EmailAddress::isPlain("vic\x00tim@example.org"));
    }
}
