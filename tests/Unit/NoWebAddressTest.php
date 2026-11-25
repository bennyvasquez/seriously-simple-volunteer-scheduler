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

    public function test_look_alike_punctuation_counts_as_the_real_thing(): void
    {
        foreach (["https\u{FF1A}//evil.example", "www\u{FF0E}evil.example", "www\u{3002}evil.example", "ww\u{3164}w.evil.example", "ww\u{115F}w.evil.example", "ww\u{FFA0}w.evil.example", "https\u{FF1A}\u{FF0F}\u{FF0F}evil.example"] as $text) {
            $this->assertTrue(NoWebAddress::check($text), $text);
        }
    }

    public function test_ordinary_punctuation_is_still_fine(): void
    {
        $this->assertFalse(NoWebAddress::check('Meet at 6:30. Bring gloves.'));
        $this->assertFalse(NoWebAddress::check('Front desk / Woodshop'));
    }
}
