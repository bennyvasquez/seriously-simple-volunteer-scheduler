<?php

namespace Tests\Browser;

use App\Models\CoverRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\DuskTestCase;

/**
 * An automated accessibility check of the volunteer's Home, All my shifts and Emails screens, and of releasing and taking a shift, which find what a tool can find and don't replace trying them with a keyboard and a screen reader.
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

    /** The volunteer's first shift that nobody has released, so its pages show only the volunteer's own place (see ExampleOrganizationSeeder::releasableShiftOf()). */
    private function volunteersShift(User $volunteer): Shift
    {
        return ExampleOrganizationSeeder::releasableShiftOf($volunteer);
    }

    public function test_the_release_form_and_its_check_step(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $shift = $this->volunteersShift($volunteer);

        $this->browse(function (Browser $browser) use ($volunteer, $shift) {
            $browser->loginAs($volunteer)->visit(route('shifts.release', $shift))->assertSee('Which shifts can\'t you make?');
            $this->assertAccessible($browser);

            // The dates start on the shift's own date, so the check lists just it.
            $browser->radio('scope', 'range')->press('Release this shift')->waitForText('You\'d be off this shift')->assertSee('Change the dates');
            $this->assertAccessible($browser);
        });
    }

    public function test_the_release_form_with_an_error(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $shift = $this->volunteersShift($volunteer);

        $this->browse(function (Browser $browser) use ($volunteer, $shift) {
            $browser->loginAs($volunteer)->visit(route('shifts.release', $shift))->press('Release this shift')->waitForText('Pick which shifts you can\'t make.');
            $this->assertAccessible($browser);
        });
    }

    public function test_the_take_page_for_a_cover_request_and_its_yours(): void
    {
        $sebnem = User::where('email', 'sebnem@example.org')->firstOrFail();
        $shift = CoverRequest::query()->whereNull('filled_at')->firstOrFail()->shift;

        $this->browse(function (Browser $browser) use ($sebnem, $shift) {
            $browser->loginAs($sebnem)->visit(route('shifts.take', $shift))->assertSee('Can you cover this one?')->assertSee('Shadowing: Rosa');
            $this->assertAccessible($browser);

            $browser->press('Take this shift')->waitForText('It\'s yours. Thanks, Şebnem!');
            $this->assertAccessible($browser);
        });
    }

    public function test_the_take_page_with_choices(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $slot = WeeklySlot::query()->whereDoesntHave('regulars')->firstOrFail();
        $shift = Shift::query()->where('weekly_slot_id', $slot->id)->where('starts_at', '>', now())->orderBy('starts_at')->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer, $shift) {
            $browser->loginAs($volunteer)->visit(route('shifts.take', $shift))->assertSee('How long do you want it?')->assertSee('Every Sunday');
            $this->assertAccessible($browser);

            $browser->press('Take this shift')->waitForText('Pick how long you want it.');
            $this->assertAccessible($browser);
        });
    }

    public function test_someone_already_took_this_one(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $full = Shift::query()->whereNull('cancelled_at')->where('starts_at', '>', now())->whereNotNull('weekly_slot_id')->with('assignments')->orderBy('starts_at')->get()
            ->first(fn (Shift $shift) => $shift->openPlaces() === 0 && ! $shift->assignments->contains('user_id', $volunteer->id));

        $this->browse(function (Browser $browser) use ($volunteer, $full) {
            $browser->loginAs($volunteer)->visit(route('shifts.take', $full))->assertSee('Someone already took this one')->assertSee('Other open shifts you can take');
            $this->assertAccessible($browser);
        });
    }
}
