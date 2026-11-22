<?php

namespace Tests\Browser;

use App\Models\Shift;
use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The whole "Can't make it" to "It's yours" flow in a real browser, as two volunteers: one releases a shift with a note, the other takes it.
 */
class ReleaseAndTakeTest extends DuskTestCase
{
    public function test_one_volunteer_releases_a_shift_and_another_takes_it(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $sebnem = User::where('email', 'sebnem@example.org')->firstOrFail();

        // A Tuesday front desk shift of the volunteer's at least a week away, clear of the seeded cover request.
        $shift = Shift::query()->whereNull('cancelled_at')->where('starts_at', '>', now()->addDays(7))
            ->whereHas('assignments', fn ($assignments) => $assignments->where('user_id', $volunteer->id))
            ->orderBy('starts_at')->firstOrFail();
        $date = $shift->local_date->format('l, F j');

        // One browser, since the browser container runs one session at a time: the volunteer releases, logs out, and Şebnem takes it.
        $this->browse(function (Browser $browser) use ($volunteer, $sebnem, $shift, $date) {
            $browser->loginAs($volunteer)->visit(route('shifts.release', $shift))
                ->assertSee('Which shifts can\'t you make?')
                ->radio('scope', 'one')
                ->type('note', 'The register key is in the top drawer.')
                ->press('Release this shift')
                ->waitForText('You\'re off '.$date)
                ->assertSee('We\'ve asked other volunteers to cover your Front desk shift.');

            $browser->logout()->loginAs($sebnem)->visit(route('shifts.take', $shift))
                ->assertSee('Can you cover this one?')
                ->assertSee('Jamie can\'t make it. You\'d take this date only.')
                ->assertSee('The register key is in the top drawer.')
                ->press('Take this shift')
                ->waitForText('It\'s yours. Thanks, Şebnem!')
                ->assertSee('told Jamie');
        });

        $this->assertTrue($shift->assignments()->where('user_id', $sebnem->id)->exists());
        $this->assertFalse($shift->assignments()->where('user_id', $volunteer->id)->exists());
    }
}
