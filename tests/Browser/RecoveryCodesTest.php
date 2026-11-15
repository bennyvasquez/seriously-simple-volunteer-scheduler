<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

class RecoveryCodesTest extends DuskTestCase
{
    public function test_copy_puts_every_code_on_the_clipboard(): void
    {
        $this->browse(function (Browser $browser) {
            $this->openRecoveryCodes($browser);
            $this->stubClipboard($browser, 'Promise.resolve()');

            $codes = $browser->script("return [...document.querySelectorAll('#recovery-codes .code')].map(c => c.textContent)")[0];
            $this->assertNotEmpty($codes);

            $browser->press('Copy codes')
                ->waitUntil("document.getElementById('copy-codes').textContent === 'Copied'");

            $this->assertSame(implode("\n", $codes), $browser->script('return window.copiedText')[0]);
            $browser->assertMissing('#copy-failed')
                ->assertDontSee("Couldn't copy");
        });
    }

    public function test_a_failed_copy_says_so(): void
    {
        $this->browse(function (Browser $browser) {
            $this->openRecoveryCodes($browser);
            $this->stubClipboard($browser, "Promise.reject(new Error('denied'))");

            $browser->press('Copy codes')
                ->waitForText("Couldn't copy. Select the codes and copy them instead.")
                ->assertSeeIn('#copy-codes', 'Copy codes');
        });
    }

    /**
     * Gets to the codes the way a person does: Login and security, then "Make new recovery codes", then the password check.
     */
    private function openRecoveryCodes(Browser $browser): void
    {
        $lead = User::where('email', ExampleOrganizationSeeder::AREA_LEAD)->firstOrFail();

        $browser->loginAs($lead)
            ->visit('/account')
            ->press('Make new recovery codes');

        // Fortify asks staff to confirm their password before it makes new codes.
        $browser->waitForLocation('/user/confirm-password')
            ->type('password', ExampleOrganizationSeeder::PASSWORD)
            ->press('Confirm');

        // Confirming sends the person back to Login and security rather than carrying on with the button they pressed, so they press it again.
        $browser->waitForLocation('/account')
            ->press('Make new recovery codes');

        $browser->waitForText('Save your recovery codes')
            ->assertVisible('#copy-codes');
    }

    /**
     * Replaces the browser's clipboard write with one that keeps the text on the window and then succeeds or fails as given, because a test can't read the real clipboard.
     */
    private function stubClipboard(Browser $browser, string $result): void
    {
        $browser->script(<<<JS
            Object.defineProperty(navigator.clipboard, 'writeText', {
                configurable: true,
                value: (text) => {
                    window.copiedText = text;
                    return {$result};
                },
            });
        JS);
    }
}
