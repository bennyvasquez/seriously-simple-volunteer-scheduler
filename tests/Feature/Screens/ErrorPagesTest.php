<?php

namespace Tests\Feature\Screens;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expired_form_explains_what_to_do(): void
    {
        // Tests skip the CSRF check, so this route throws what the check throws.
        Route::post('/test-expired-form', fn () => throw new TokenMismatchException('CSRF token mismatch.'))->middleware('web');

        $this->post('/test-expired-form')->assertStatus(419)
            ->assertSee('This page was open too long')
            ->assertSee('Go back and try again.')
            ->assertSee('href="'.route('login').'"', false)
            ->assertSee('<link rel="stylesheet" href="/css/app.css">', false);
    }

    public function test_too_many_tries_says_how_long_to_wait(): void
    {
        Notification::fake();

        for ($i = 0; $i < 3; $i++) {
            $this->post(route('login-link.request'), ['email' => 'sam@example.org']);
        }

        $response = $this->post(route('login-link.request'), ['email' => 'sam@example.org'])->assertStatus(429)
            ->assertSee('Too many tries')
            ->assertSee('Wait 10 minutes, then try again.');
        // The sign-in requests carry no rate limit headers (HideRateLimitHeaders); the page reads the wait from the limit itself.
        $this->assertNull($response->headers->get('Retry-After'));
    }

    public function test_too_many_tries_without_a_wait_still_says_what_to_do(): void
    {
        Route::get('/test-too-many', fn () => abort(429))->middleware('web');

        $this->get('/test-too-many')->assertStatus(429)->assertSee('Wait a few minutes, then try again.');
    }

    public function test_a_long_wait_is_said_in_hours_rounded_up(): void
    {
        Route::get('/test-long-wait', fn () => abort(429, '', ['Retry-After' => 82000]))->middleware('web');

        $this->get('/test-long-wait')->assertStatus(429)->assertSee('Wait about 23 hours, then try again.');
    }

    public function test_a_wait_of_ninety_minutes_or_less_is_said_in_minutes(): void
    {
        Route::get('/test-ninety', fn () => abort(429, '', ['Retry-After' => 5400]))->middleware('web');
        Route::get('/test-just-over', fn () => abort(429, '', ['Retry-After' => 5401]))->middleware('web');

        $this->get('/test-ninety')->assertStatus(429)->assertSee('Wait 90 minutes, then try again.');
        $this->get('/test-just-over')->assertStatus(429)->assertSee('Wait about 2 hours, then try again.');
    }

    public function test_a_page_you_cannot_open_links_home(): void
    {
        $volunteer = User::factory()->create();

        $this->actingAs($volunteer)->get(route('people.security', $volunteer))->assertForbidden()
            ->assertSee('You can\'t open this page')
            ->assertSee('href="'.route('home').'"', false);
    }

    public function test_a_missing_page_links_home(): void
    {
        $this->get('/no-such-page')->assertNotFound()
            ->assertSee('We can\'t find that page')
            ->assertSee('href="'.route('home').'"', false);
    }
}
