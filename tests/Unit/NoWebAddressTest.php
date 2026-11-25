<?php

namespace Tests\Unit;

use App\Rules\NoWebAddress;
use PHPUnit\Framework\TestCase;

class NoWebAddressTest extends TestCase
{
    public function test_web_addresses_are_refused_however_they_are_spelled(): void
    {
        $this->assertTrue(NoWebAddress::check('see https://x.example'));
        $this->assertTrue(NoWebAddress::check('WWW.x.example'));
        $this->assertTrue(NoWebAddress::check("http\u{200B}://x"));
        $this->assertTrue(NoWebAddress::check('HTTPS://X'));
    }

    public function test_ordinary_text_is_fine(): void
    {
        $this->assertFalse(NoWebAddress::check('Back at 3:30, sorry!'));
        $this->assertFalse(NoWebAddress::check('see the wiki'));
    }

    public function test_a_word_ending_in_www_dot_is_refused_too(): void
    {
        // A known false positive the email editor already had.
        $this->assertTrue(NoWebAddress::check('awww. sorry'));
    }
}
