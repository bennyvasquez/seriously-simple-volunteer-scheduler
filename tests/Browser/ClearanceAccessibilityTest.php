<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\DuskTestCase;

/**
 * An automated accessibility check of the person page's "Shadowing and clearance" panel (screen 9), before and after a coordinator clears someone. It finds what a tool can find and doesn't replace trying it with a keyboard and a screen reader.
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
                ->waitForText('Riley is cleared for regular shifts.')
                ->assertSee('Cleared for regular shifts since');
            $this->assertAccessible($browser);
        });
    }
}
