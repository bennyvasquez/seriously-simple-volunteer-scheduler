<?php

namespace Tests\Unit;

use App\Support\PersonName;
use PHPUnit\Framework\TestCase;

class PersonNameTest extends TestCase
{
    public function test_ordinary_names_are_fine(): void
    {
        foreach (['O\'Brien', 'Nguyễn', 'José', 'Mary-Kate', 'Mary_Ann', 'Sam & Alex'] as $name) {
            $this->assertNull(PersonName::problem($name), $name);
        }
    }

    public function test_link_characters_and_web_addresses_are_refused(): void
    {
        foreach (['[Sign in](https://x) Smith', '<b>Sam</b>', 'Sam www.x.example', "Sam\u{0007}", "Sam\nExample"] as $name) {
            $this->assertSame(PersonName::REFUSAL, PersonName::problem($name), $name);
        }
    }

    public function test_clean_trims_and_collapses_whitespace_and_invisible_characters(): void
    {
        $this->assertSame('Sam Example', PersonName::clean("Sam\u{00A0}\u{00A0}Example\u{200B}"));
        $this->assertSame('Sam Example', PersonName::clean("  Sam \t\n Example "));
    }
}
