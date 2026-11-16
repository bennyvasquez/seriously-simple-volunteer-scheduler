<?php

namespace Tests\Browser\Concerns;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;

trait OpensRecoveryCodes
{
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
            ->type('password', ExampleOrganizationSeeder::password())
            ->press('Confirm');

        // Confirming sends the person back to Login and security rather than carrying on with the button they pressed, so they press it again.
        $browser->waitForLocation('/account')
            ->press('Make new recovery codes');

        $browser->waitForText('Save your recovery codes')
            ->assertVisible('#copy-codes');
    }
}
