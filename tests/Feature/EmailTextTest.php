<?php

namespace Tests\Feature;

use App\Notifications\EmailText;
use InvalidArgumentException;
use Tests\TestCase;

class EmailTextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        app('translator')->addLines([
            'emails.test_email.hello' => 'Hi {first_name}, see you at {area}.',
            'emails.test_email.plain' => 'No variables here.',
            'emails.test_email.braces' => 'Keep { this }, {That}, {} and {a-b} as they are.',
            'emails.test_email.count.one' => '{names} is shadowing.',
            'emails.test_email.count.other' => '{names} are shadowing.',
        ], 'en');
    }

    public function test_a_line_fills_in_its_variables(): void
    {
        $this->assertSame('Hi Jordan, see you at Front desk.', EmailText::line('test_email.hello', ['first_name' => 'Jordan', 'area' => 'Front desk']));
    }

    public function test_a_missing_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailText::line('test_email.nope');
    }

    public function test_an_unfilled_variable_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailText::line('test_email.hello', ['first_name' => 'Jordan']);
    }

    public function test_an_unused_variable_is_ignored(): void
    {
        $this->assertSame('No variables here.', EmailText::line('test_email.plain', ['area' => 'Front desk']));
    }

    public function test_choice_picks_one_or_other(): void
    {
        $this->assertSame('Alex R. is shadowing.', EmailText::choice('test_email.count', 1, ['names' => 'Alex R.']));
        $this->assertSame('Alex R. and Sam E. are shadowing.', EmailText::choice('test_email.count', 2, ['names' => 'Alex R. and Sam E.']));
    }

    public function test_braces_that_arent_a_variable_name_are_left_alone(): void
    {
        $this->assertSame('Keep { this }, {That}, {} and {a-b} as they are.', EmailText::line('test_email.braces'));
    }

    public function test_a_value_that_looks_like_a_variable_is_not_filled_in_again(): void
    {
        $this->assertSame('Hi {area}, see you at Front desk.', EmailText::line('test_email.hello', ['first_name' => '{area}', 'area' => 'Front desk']));
    }
}
