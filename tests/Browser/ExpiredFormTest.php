<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * A form pressed after its page was open longer than the session, in a real browser: the session cookie is deleted to stand in for the expiry.
 */
class ExpiredFormTest extends DuskTestCase
{
    public function test_taking_a_shift_on_an_expired_page_asks_to_log_in_and_comes_back_to_try_again(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $sebnem = User::where('email', 'sebnem@example.org')->firstOrFail();
        $shift = ExampleOrganizationSeeder::releasableShiftOf($volunteer);
        $takePath = route('shifts.take', $shift, false);

        $message = 'This page was open too long, so nothing was saved. Please try again.';

        $this->browse(function (Browser $browser) use ($volunteer, $sebnem, $shift, $takePath, $message) {
            // The volunteer releases the shift so there is a place to take.
            $browser->loginAs($volunteer)->visit(route('shifts.release', $shift))
                ->radio('scope', 'one')
                ->press('Release this shift')
                ->waitForText('You\'re off');

            $browser->logout()->loginAs($sebnem)->visit(route('shifts.take', $shift))
                ->assertSee('Can you cover this one?')
                ->deleteCookie(config('session.cookie'))
                ->press('Take this shift')
                ->waitForLocation('/login')
                ->assertSee($message)
                ->type('email', 'sebnem@example.org')
                ->type('password', ExampleOrganizationSeeder::password())
                ->press('Log in with my password')
                ->waitForLocation($takePath)
                ->assertSee('Can you cover this one?')
                ->assertSee('Take this shift');
        });

        $this->assertFalse($shift->assignments()->where('user_id', $sebnem->id)->exists());
    }
}
