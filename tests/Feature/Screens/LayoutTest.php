<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Tests\TestCase;

class LayoutTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_going_home_is_sent_to_log_in(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    public function test_home_greets_the_person(): void
    {
        $this->actingAs(User::factory()->create(['name' => 'Sam Rivera']))->get('/')->assertOk()->assertSee('Hi Sam');
    }

    public function test_home_says_just_hi_when_there_is_no_name(): void
    {
        $this->actingAs(User::factory()->create(['name' => '   ']))->get('/')->assertOk()->assertSee('<h1>Hi</h1>', false);
    }

    public function test_pages_use_the_local_stylesheet_and_font_only(): void
    {
        $html = $this->get('/login')->getContent();

        $this->assertStringContainsString('/css/app.css', $html);
        $this->assertStringNotContainsString('fonts.googleapis.com', $html);
        $this->assertStringNotContainsString('@vite', $html);
        $this->assertFileExists(public_path('fonts/OFL.txt'));
    }

    public function test_a_field_ties_its_hint_and_error_to_the_input(): void
    {
        $errors = (new ViewErrorBag)->put('default', new MessageBag(['email' => ['Enter a valid email address.']]));

        $html = $this->withViewErrors($errors->getBag('default')->toArray())->blade(
            '<x-field name="email" type="email" label="Email address" hint="We email you a link." />'
        );

        $html->assertSee('<label for="email">Email address</label>', false);
        $html->assertSee('aria-invalid="true"', false);
        $html->assertSee('aria-describedby="email-hint email-error"', false);
        $html->assertSee('Enter a valid email address.');
    }
}
