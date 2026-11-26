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
        foreach (['[Sign in](https://x) Smith', '<b>Sam</b>', 'Sam www.x.example'] as $name) {
            $this->assertSame(PersonName::REFUSAL, PersonName::problem($name), $name);
        }
    }

    public function test_clean_trims_and_collapses_whitespace_and_invisible_characters(): void
    {
        $this->assertSame('Sam Example', PersonName::clean("Sam\u{00A0}\u{00A0}Example\u{200B}"));
        $this->assertSame('Sam Example', PersonName::clean("  Sam \t\n Example "));
    }

    public function test_clean_removes_control_characters_and_turns_tabs_and_line_breaks_into_spaces(): void
    {
        $this->assertSame('Sam Example', PersonName::clean("Sam \x07 Example"));
        $this->assertSame('Sam Example', PersonName::clean("Sam\tExample"));
        $this->assertSame('Sam Example', PersonName::clean("Sam\r\nExample"));
    }

    public function test_clean_keeps_the_joiner_characters_some_languages_need(): void
    {
        $persian = "می\u{200C}خواهم";
        $this->assertSame($persian, PersonName::clean($persian));
        $this->assertSame("a\u{200D}b", PersonName::clean("a\u{200D}b"));
        $this->assertSame('ab', PersonName::clean("a\u{200E}b"));
    }

    public function test_stray_tabs_and_line_breaks_are_not_a_problem_but_control_characters_are(): void
    {
        $this->assertNull(PersonName::problem("Sam\tExample"));
        $this->assertNull(PersonName::problem("Sam\r\nExample"));
        $this->assertSame(PersonName::CONTROL_REFUSAL, PersonName::problem("Sam\x07 Example"));
        $this->assertSame(PersonName::CONTROL_REFUSAL, PersonName::problem("Sam \e[2K Example"));
        $this->assertSame(PersonName::REFUSAL, PersonName::problem("[x]\t(y)"));
    }
}
