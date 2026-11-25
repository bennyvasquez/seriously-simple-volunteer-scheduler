<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Choosing a repeat on the add screen reloads the form to show the repeat options. That script lives in public/js/refresh-form.js, because the Content-Security-Policy header stops inline handlers from running.
 */
class RefreshFormTest extends DuskTestCase
{
    public function test_choosing_a_repeat_shows_the_repeat_options(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();

        $this->browse(function (Browser $browser) use ($coordinator) {
            $browser->resize(1280, 900)->loginAs($coordinator)->visit(route('calendar.add'))
                ->assertMissing('input[name=fills_by]')
                ->select('repeats', 'weekly')
                ->waitFor('input[name=fills_by]')
                ->assertSelected('repeats', 'weekly');
        });
    }
}
