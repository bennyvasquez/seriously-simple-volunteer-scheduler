<?php

namespace Tests\Browser;

use App\Enums\AssignmentType;
use App\Http\Controllers\Volunteer\HomeController;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * Clearance and shadowing in a real browser (spec section 9): the example trainee signs up for a shadow spot from Home, and a coordinator clears someone from their person page with a note for the change log.
 */
class ShadowingTest extends DuskTestCase
{
    public function test_a_trainee_signs_up_for_a_shadow_spot_from_home(): void
    {
        $trainee = User::where('email', ExampleOrganizationSeeder::TRAINEE)->firstOrFail();
        // Home lists the open shadow spots in this order, so the first "Shadow this shift" button is for this shift.
        $shift = app(HomeController::class)->openShadowSpots($trainee)->firstOrFail();
        $date = $shift->local_date->format('l, F j');
        $area = $shift->area->name;

        $this->browse(function (Browser $browser) use ($trainee, $date, $area) {
            $browser->loginAs($trainee)->visit('/')
                ->assertSee('Shadow shifts done: 0 of 2')
                ->assertSee('After 2, a coordinator reviews and clears you to take regular shifts.')
                ->assertSee('No shadow shifts in the next two weeks.')
                ->clickLink('Shadow this shift')
                ->waitForText('Can you shadow this one?')
                ->assertSee($date)
                ->press('Shadow this shift')
                ->waitForText('You\'re shadowing '.$area.' on '.$date.'.')
                ->assertPathIs('/')
                ->assertDontSee('No shadow shifts in the next two weeks.');

            // The first section on the not-cleared Home is "Your next shadow shift", and it now holds this shift.
            $browser->with('main section.shift-section', function (Browser $section) use ($date, $area) {
                $section->assertSee('Your next shadow shift')->assertSee($date)->assertSee($area)->assertSee('You\'ll shadow');
            });
        });

        $this->assertTrue($shift->assignments()->where('user_id', $trainee->id)->where('type', AssignmentType::Shadow)->exists());
    }

    public function test_a_coordinator_clears_someone_with_a_note(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();
        // Rosa is shadowing a seeded shift and isn't cleared.
        $rosa = User::where('email', 'rosa@example.org')->firstOrFail();
        $this->assertFalse($rosa->isCleared());

        $this->browse(function (Browser $browser) use ($coordinator, $rosa) {
            $browser->loginAs($coordinator)->visit(route('people.show', $rosa))
                ->assertSee('Shadowing and clearance')
                ->assertSee('Shadow shifts done: 0 of 2')
                ->type('note', 'Knows the front desk well already.')
                ->press('Clear for regular shifts')
                ->waitForText('Rosa D. is cleared for regular shifts.')
                ->assertSee('Cleared for regular shifts since')
                ->assertDontSee('Shadow shifts done')
                ->assertSee('Move back to shadowing');

            // The note is kept for the change log, under the Clearance kind.
            $browser->visit(route('change-log.index', ['kind' => 'clearance']))
                ->assertSee('Cleared Rosa Delgado for regular shifts')
                ->assertSee('Note: Knows the front desk well already.');
        });

        $this->assertTrue($rosa->fresh()->isCleared());
        $entry = ChangeLogEntry::query()->where('action', 'user.cleared')->where('affected_user_id', $rosa->id)->sole();
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertSame('person_page', $entry->details['via']);
        $this->assertSame('Knows the front desk well already.', $entry->changes['note']['new']);
    }
}
