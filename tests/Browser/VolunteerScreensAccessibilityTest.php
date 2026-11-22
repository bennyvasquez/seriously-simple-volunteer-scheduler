<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\DuskTestCase;

/**
 * An automated accessibility check of the volunteer's Home, All my shifts and Emails screens, which find what a tool can find and don't replace trying them with a keyboard and a screen reader.
 */
class VolunteerScreensAccessibilityTest extends DuskTestCase
{
    use ChecksAccessibility;

    public function test_home(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->loginAs($volunteer)->visit('/')->assertSee('Your next shifts')->assertSee('Open shifts you can take');

            $this->assertAccessible($browser);
        });
    }

    public function test_all_my_shifts(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->loginAs($volunteer)->visit('/my-shifts')->assertSee('All my shifts');

            $this->assertAccessible($browser);
        });
    }

    public function test_emails(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->loginAs($volunteer)->visit('/account/emails')->assertSee('Always sent');

            $this->assertAccessible($browser);
        });
    }

    public function test_turning_cover_request_emails_off(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->loginAs($volunteer)->visit('/account/emails')->uncheck('cover_request_emails')->press('Save')->waitForText('Saved.')->assertNotChecked('cover_request_emails');
        });

        $this->assertFalse($volunteer->fresh()->cover_request_emails);
    }
}
