<?php

namespace Tests\Browser;

use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\Browser\Concerns\OpensRecoveryCodes;
use Tests\DuskTestCase;

/**
 * An automated accessibility check of each sign-in screen. It finds what a tool can find, such as missing labels and low contrast, and doesn't replace trying the screens with a keyboard and a screen reader.
 */
class SignInAccessibilityTest extends DuskTestCase
{
    use ChecksAccessibility;
    use OpensRecoveryCodes;

    public function test_log_in(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')->assertSee('Log in');

            $this->assertAccessible($browser);
        });
    }

    public function test_check_your_email(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', ExampleOrganizationSeeder::VOLUNTEER)
                ->press('Email me a link')
                ->waitForText('Check your email');

            $this->assertAccessible($browser);
        });
    }

    public function test_a_password_login_error(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', ExampleOrganizationSeeder::VOLUNTEER)
                ->type('password', 'not-the-password')
                ->press('Log in with my password')
                ->waitForLocation('/login')
                ->waitFor('#email-error');

            $this->assertAccessible($browser);
        });
    }

    public function test_forgot_password(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/forgot-password')->assertSee('Reset your password');

            $this->assertAccessible($browser);
        });
    }

    public function test_the_code_screen(): void
    {
        $this->browse(function (Browser $browser) {
            $browser->visit('/login')
                ->type('email', ExampleOrganizationSeeder::ADMIN)
                ->type('password', ExampleOrganizationSeeder::password())
                ->press('Log in with my password')
                ->waitForLocation('/two-factor-challenge');

            $this->assertAccessible($browser);
        });
    }

    public function test_account_security_setup(): void
    {
        $person = User::where('email', ExampleOrganizationSeeder::NEEDS_SETUP)->firstOrFail();

        $this->browse(function (Browser $browser) use ($person) {
            $browser->loginAs($person)
                ->visit('/account/security')
                ->assertPathIs('/account/security');

            $this->assertAccessible($browser);
        });
    }

    public function test_recovery_codes(): void
    {
        $this->browse(function (Browser $browser) {
            $this->openRecoveryCodes($browser);

            $this->assertAccessible($browser);
        });
    }

    public function test_login_and_security(): void
    {
        $lead = User::where('email', ExampleOrganizationSeeder::AREA_LEAD)->firstOrFail();

        $this->browse(function (Browser $browser) use ($lead) {
            $browser->loginAs($lead)
                ->visit('/account')
                ->assertPathIs('/account');

            $this->assertAccessible($browser);
        });
    }

    public function test_adding_a_first_password_needs_a_fresh_link_sign_in(): void
    {
        $person = User::factory()->withoutPassword()->create(['email' => 'no-password@example.org']);

        $this->browse(function (Browser $browser) use ($person) {
            // Signed in some other way: no form, a way to get a new link instead.
            $browser->loginAs($person)
                ->visit('/account')
                ->assertSee('Add a password (optional)')
                ->assertDontSee('Save password')
                ->assertSee('Log out to get a new link');

            $this->assertAccessible($browser);

            $browser->press('Log out to get a new link')
                ->waitForLocation('/login');

            // Right after opening an emailed link, the form is there.
            $url = app(IssueLoginLink::class)->handle($person, LoginLinkPurpose::SignIn);
            $browser->visit(parse_url($url, PHP_URL_PATH))
                ->press('Log me in')
                ->waitForLocation('/')
                ->visit('/account')
                ->assertSee('Save password')
                ->assertDontSee('Log out to get a new link');

            $this->assertAccessible($browser);
        });
    }
}
