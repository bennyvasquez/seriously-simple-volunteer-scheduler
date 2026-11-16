<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class LoginPageTest extends DuskTestCase
{
    public function test_the_login_page_opens(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->assertSee('Log in');
        });
    }

    public function test_email_me_a_link_leaves_the_password_out(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login');
            $this->recordSubmit($browser);

            $browser->type('email', ExampleOrganizationSeeder::VOLUNTEER)
                ->type('password', ExampleOrganizationSeeder::PASSWORD)
                ->check('remember')
                ->press('Email me a link');

            $recorded = $this->recordedSubmit($browser);

            $this->assertSame('', $recorded['password']);
            $this->assertFalse($recorded['remember']);
        });
    }

    public function test_log_in_with_my_password_keeps_the_password(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login');
            $this->recordSubmit($browser);

            $browser->type('email', ExampleOrganizationSeeder::VOLUNTEER)
                ->type('password', ExampleOrganizationSeeder::PASSWORD)
                ->check('remember')
                ->press('Log in with my password');

            $recorded = $this->recordedSubmit($browser);

            $this->assertSame(ExampleOrganizationSeeder::PASSWORD, $recorded['password']);
            $this->assertTrue($recorded['remember']);
        });
    }

    public function test_enter_in_the_password_box_logs_in_with_the_password(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->visit('/login')
                ->type('email', ExampleOrganizationSeeder::VOLUNTEER)
                ->type('password', ExampleOrganizationSeeder::PASSWORD)
                ->keys('input[name=password]', '{enter}')
                ->waitForLocation('/')
                ->assertAuthenticatedAs($volunteer);
        });
    }

    public function test_enter_in_the_email_box_still_sends_a_link(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', ExampleOrganizationSeeder::VOLUNTEER)
                ->keys('input[name=email]', '{enter}')
                ->waitForText('Check your email')
                ->assertSee('has an account, we\'ve emailed it a link');
        });
    }

    /**
     * Adds a submit listener after the page's own, so it sees the form as the page's script left it, and stops the form from being sent.
     */
    private function recordSubmit(Browser $browser): void
    {
        $browser->script(<<<'JS'
            const form = document.getElementById('login-form');
            form.addEventListener('submit', (event) => {
                window.recordedSubmit = {
                    password: form.elements.password.value,
                    remember: form.elements.remember.checked,
                };
                event.preventDefault();
            });
        JS);
    }

    /**
     * @return array{password: string, remember: bool}
     */
    private function recordedSubmit(Browser $browser): array
    {
        $browser->waitUntil('window.recordedSubmit !== undefined');

        return $browser->script('return window.recordedSubmit')[0];
    }
}
