<?php

namespace Tests\Browser;

use App\Enums\AssignmentType;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\DuskTestCase;

/**
 * An automated accessibility check of the person page's "Shadowing and clearance" panel (screen 9), before and after a coordinator clears someone, of the move back page (screen 10), with and without its error, of the Settings page's Shadowing section as a coordinator sees it, and of the shift panel's "Mark no-show" button on a shift that has ended. It finds what a tool can find and doesn't replace trying it with a keyboard and a screen reader.
 */
class ClearanceAccessibilityTest extends DuskTestCase
{
    use ChecksAccessibility;

    public function test_clearing_someone_from_the_person_page(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();
        $trainee = User::where('email', ExampleOrganizationSeeder::TRAINEE)->firstOrFail();

        $this->browse(function (Browser $browser) use ($coordinator, $trainee) {
            $browser->loginAs($coordinator)->visit(route('people.show', $trainee))
                ->assertSee('Shadowing and clearance')
                ->assertSee('Shadow shifts done: 0 of 2');
            $this->assertAccessible($browser);

            $browser->type('note', 'Ready early.')->press('Clear for regular shifts')
                ->waitForText('Riley B. is cleared for regular shifts.')
                ->assertSee('Cleared for regular shifts since');
            $this->assertAccessible($browser);
        });
    }

    public function test_moving_someone_back_to_shadowing(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $first = $volunteer->shortName();

        $this->browse(function (Browser $browser) use ($coordinator, $volunteer, $first) {
            $browser->loginAs($coordinator)->visit(route('people.show', $volunteer))
                ->assertSee('Cleared for regular shifts since')
                ->clickLink('Move back to shadowing')
                ->waitForText('Move '.$first.' back to shadowing?')
                ->assertSee('Keep them')
                ->assertSee('Release them');
            $this->assertAccessible($browser);

            $browser->press('Move back to shadowing')->waitForText('Choose keep or release.');
            $this->assertAccessible($browser);

            $browser->radio('existing', 'keep')->press('Move back to shadowing')
                ->waitForText($first.' is back to shadowing.')
                ->assertSee('Shadow shifts done');
        });
    }

    public function test_the_shadowing_setting_as_a_coordinator(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();

        $this->browse(function (Browser $browser) use ($coordinator) {
            // A coordinator sees only the Shadowing section; the rest of Settings is for admins.
            $browser->loginAs($coordinator)->visit(route('settings'))
                ->assertSee('Coordinators can change the shadowing setting. Admins change the rest.')
                ->assertSee('Shadow shifts before review')
                ->assertDontSee('Make shifts this many weeks ahead');
            $this->assertAccessible($browser);

            $browser->type('required_shadow_shifts', '3')->press('Save shadowing')->waitForText('Saved the settings.')
                ->assertInputValue('required_shadow_shifts', '3');
            $this->assertAccessible($browser);
        });

        $this->assertSame(3, Organization::current()->fresh()->required_shadow_shifts);
    }

    public function test_marking_a_no_show_on_the_shift_panel(): void
    {
        $lead = User::where('email', ExampleOrganizationSeeder::AREA_LEAD)->firstOrFail();
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $rosa = User::where('email', 'rosa@example.org')->firstOrFail();
        $frontDesk = Area::where('name', 'Front desk')->firstOrFail();

        // The example data has no past shifts, so this one ended an hour ago, with a regular and Rosa shadowing.
        $ends = now()->subHour()->startOfMinute();
        $starts = $ends->copy()->subHours(3);
        $shift = Shift::create([
            'area_id' => $frontDesk->id, 'local_date' => $starts->copy()->setTimezone(Organization::current()->timezone)->toDateString(),
            'starts_at' => $starts, 'ends_at' => $ends, 'people_needed' => 1, 'shadow_spots' => 1,
        ]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $volunteer->id, 'type' => AssignmentType::Regular]);
        $shadow = Assignment::create(['shift_id' => $shift->id, 'user_id' => $rosa->id, 'type' => AssignmentType::Shadow]);

        $this->browse(function (Browser $browser) use ($lead, $shift) {
            // The Front desk's lead marks no-shows in their area.
            $browser->loginAs($lead)->visit(route('calendar.shift', $shift))
                ->assertSee('Shadowing')
                ->assertSee('Mark no-show');
            $this->assertAccessible($browser);

            $browser->press('Mark no-show')->waitForText('as a no-show.')->assertSee('Marked Rosa')->assertSee('Undo no-show');
            $this->assertAccessible($browser);
        });

        $this->assertNotNull($shadow->fresh()->no_show_at);
    }
}
