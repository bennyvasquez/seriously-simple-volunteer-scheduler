<?php

namespace Tests\Browser;

use App\Models\ChangeNotice;
use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The whole time change flow in a real browser, as two people: a coordinator moves a Tuesday's start time with "This shift and the ones after it", then the volunteer on it opens the answer page from the email's URL, logs in, presses "Still works for me", and the coordinator's panel shows who confirmed.
 */
class ChangeNoticeTest extends DuskTestCase
{
    public function test_a_time_change_is_asked_and_the_answer_shows_on_the_coordinator_panel(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $shift = ExampleOrganizationSeeder::releasableShiftOf($volunteer);

        $this->browse(function (Browser $browser) use ($coordinator, $volunteer, $shift) {
            $browser->resize(1280, 900)->loginAs($coordinator)->visit(route('calendar.shift', $shift).'?edit=1')
                ->assertSee('Edit which shifts?')
                ->radio('edit', 'following')
                ->press('Continue')
                ->waitForText('First date');

            // Time inputs are fiddly to type into, so the value is set directly.
            $browser->script("document.querySelector('input[name=start_time]').value = '16:00';");
            $browser->press('Save the slot')->waitForText('people were emailed about their changed shifts');

            $notice = ChangeNotice::query()->where('user_id', $volunteer->id)->where('shift_id', $shift->id)->firstOrFail();
            $answerUrl = route('changes.show', $notice->batch);

            // The email's link, opened by someone who isn't logged in: they log in and come straight back.
            $browser->logout()->visit($answerUrl)->assertPathIs('/login')
                ->type('email', ExampleOrganizationSeeder::VOLUNTEER)
                ->type('password', ExampleOrganizationSeeder::password())
                ->press('Log in with my password')
                ->waitForLocation(route('changes.show', $notice->batch, false))
                ->assertSee('Does the new time still work for you?')
                ->assertSee('Still works for me')
                ->assertSee('I can\'t make the new time')
                ->press('Still works for me')
                ->waitForText('Thanks. You\'re still on at the new time.');

            $browser->logout()->loginAs($coordinator)->visit(route('calendar.shift', $shift))
                ->assertSee('Confirmed the new time');

            // The shift's other regular hasn't answered, so the panel still says so for them.
            $browser->assertSee('Hasn\'t answered yet');
        });

        $this->assertNotNull(ChangeNotice::query()->where('user_id', $volunteer->id)->where('shift_id', $shift->id)->firstOrFail()->confirmed_at);
    }
}
