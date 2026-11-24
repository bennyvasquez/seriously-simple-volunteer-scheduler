<?php

namespace Tests\Feature;

use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * A form posted after its page sat open past the session (#80): the feature test kernel skips CSRF checks, so these hand the exception handler a stale post directly.
 */
class ExpiredFormTest extends TestCase
{
    private const MESSAGE = 'This page was open too long, so nothing was saved. Please try again.';

    private function render(string $method, string $uri): TestResponse
    {
        $request = Request::create($uri, $method, ['email' => 'someone@example.org']);
        $request->setLaravelSession($this->app['session.store']);

        // The same exception Laravel's token check ends up with: prepareException turns the mismatch into a 419.
        $response = $this->app->make(ExceptionHandler::class)->render($request, new HttpException(419, 'CSRF token mismatch.', new TokenMismatchException('CSRF token mismatch.')));

        return new TestResponse($response, $request);
    }

    public function test_a_stale_take_form_goes_back_to_its_page_with_the_message(): void
    {
        $response = $this->render('POST', '/shifts/42/take');

        $response->assertRedirect('/shifts/42/take');
        $this->assertSame(self::MESSAGE, $response->getSession()->get('status'));
    }

    public function test_a_stale_login_form_goes_back_to_login_without_the_typed_email(): void
    {
        $response = $this->render('POST', '/login');

        $response->assertRedirect('/login');
        $session = $response->getSession()->all();
        $this->assertSame(self::MESSAGE, $session['status']);
        $this->assertArrayNotHasKey('_old_input', $session);
        $this->assertArrayNotHasKey('errors', $session);
        $this->assertStringNotContainsString('someone@example.org', json_encode($session));
    }

    public function test_a_stale_logout_keeps_the_419_page(): void
    {
        $this->render('POST', '/logout')->assertStatus(419);
    }

    public function test_a_stale_post_with_no_page_at_its_address_keeps_the_419_page(): void
    {
        $this->render('POST', '/no-such-page')->assertStatus(419);
    }

    public function test_the_redirect_ignores_the_referer(): void
    {
        $request = Request::create('/shifts/42/take', 'POST');
        $request->headers->set('referer', 'https://evil.example.com/phish');
        $request->setLaravelSession($this->app['session.store']);

        $response = $this->app->make(ExceptionHandler::class)->render($request, new HttpException(419, '', new TokenMismatchException));

        $this->assertSame('/shifts/42/take', parse_url($response->headers->get('Location'), PHP_URL_PATH));
        $this->assertStringNotContainsString('evil.example.com', $response->headers->get('Location'));
    }

    public function test_the_login_page_shows_the_message_without_an_email(): void
    {
        $this->withSession(['status' => self::MESSAGE])->get('/login')->assertOk()->assertSee(self::MESSAGE)->assertDontSee('someone@example.org');
    }

    public function test_a_post_with_no_session_goes_to_login_with_the_message(): void
    {
        $this->post('/shifts/42/take', ['email' => 'someone@example.org'])
            ->assertRedirect('/login')
            ->assertSessionHas('status', self::MESSAGE)
            ->assertSessionMissing('_old_input');
    }

    public function test_a_plain_visit_with_no_session_goes_to_login_without_the_message(): void
    {
        $this->get('/shifts/42/take')->assertRedirect('/login')->assertSessionMissing('status');
    }

    public function test_a_guest_posting_to_log_out_gets_no_expired_message(): void
    {
        $this->post('/logout')->assertSessionMissing('status');
    }

    public function test_the_callback_is_registered_for_a_real_token_mismatch(): void
    {
        // Laravel skips the token check when the environment is testing, so look like production for this one request.
        $this->app['env'] = 'production';

        $this->post('/login', ['email' => 'someone@example.org'], ['Sec-Fetch-Site' => 'none'])
            ->assertRedirect('/login')
            ->assertSessionHas('status', self::MESSAGE)
            ->assertSessionMissing('_old_input');
    }
}
