<?php

namespace Tests\Browser;

use App\Http\Controllers\Volunteer\HomeController;
use App\Models\ChangeNotice;
use App\Models\CoverRequest;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Database\Seeders\ExampleOrganizationSeeder;
use Illuminate\Support\Str;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\DuskTestCase;

/**
 * An automated accessibility check of the volunteer's Home (cleared or not), All my shifts and Emails screens, and of releasing, taking and shadowing a shift, which find what a tool can find and don't replace trying them with a keyboard and a screen reader.
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

    public function test_home_for_someone_not_cleared(): void
    {
        $trainee = User::where('email', ExampleOrganizationSeeder::TRAINEE)->firstOrFail();

        $this->browse(function (Browser $browser) use ($trainee) {
            $browser->loginAs($trainee)->visit('/')->assertSee('Shadow shifts done: 0 of 2')->assertSee('Shadow spots open')->assertDontSee('Open shifts you can take');

            $this->assertAccessible($browser);
        });
    }

    public function test_the_shadow_page_and_signing_up(): void
    {
        $trainee = User::where('email', ExampleOrganizationSeeder::TRAINEE)->firstOrFail();
        $shift = app(HomeController::class)->openShadowSpots($trainee)->firstOrFail();

        $this->browse(function (Browser $browser) use ($trainee, $shift) {
            $browser->loginAs($trainee)->visit(route('shifts.shadow', $shift))->assertSee('Can you shadow this one?');
            $this->assertAccessible($browser);

            $browser->press('Shadow this shift')->waitForText('You\'re shadowing')->assertSee('Your next shadow shift');
            $this->assertAccessible($browser);

            // Coming back to it now explains why there's nothing to press, with the other spots.
            $browser->visit(route('shifts.shadow', $shift))->assertSee('You\'re shadowing this shift.')->assertSee('Other shadow spots open');
            $this->assertAccessible($browser);
        });
    }

    public function test_the_schedule_on_a_computer(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->resize(1280, 900)->loginAs($volunteer)->visit('/calendar')->assertVisible('.cal-grid');

            $this->assertAccessible($browser);
        });
    }

    public function test_the_schedule_on_a_phone(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->resize(390, 844)->loginAs($volunteer)->visit('/calendar')->assertVisible('.cal-list');

            $this->assertAccessible($browser);
        });
    }

    public function test_a_volunteers_shift_panel(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $slot = WeeklySlot::query()->whereDoesntHave('regulars')->firstOrFail();
        $open = Shift::query()->where('weekly_slot_id', $slot->id)->where('starts_at', '>', now())->orderBy('starts_at')->firstOrFail();
        $own = $this->volunteersShift($volunteer);

        $this->browse(function (Browser $browser) use ($volunteer, $open, $own) {
            $browser->resize(1280, 900)->loginAs($volunteer)->visit(route('calendar.shift', $open))->assertSee('Take this shift');
            $this->assertAccessible($browser);

            $browser->visit(route('calendar.shift', $own))->assertSee('Can\'t make it');
            $this->assertAccessible($browser);
        });
    }

    /** A change notice for the volunteer on their shift: its time moved an hour later than it was. Setup only, so a screen can be checked in each state. */
    private function noticeFor(User $volunteer, Shift $shift, ?string $batch = null): ChangeNotice
    {
        return ChangeNotice::create([
            'batch' => $batch ?? (string) Str::ulid(),
            'user_id' => $volunteer->id,
            'shift_id' => $shift->id,
            'changed_by' => null,
            'was_starts_at' => $shift->starts_at->addHour(),
            'was_ends_at' => $shift->ends_at,
        ]);
    }

    public function test_the_change_notice_page_open_confirmed_and_changed_again(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $shift = $this->volunteersShift($volunteer);
        $first = $this->noticeFor($volunteer, $shift);

        $this->browse(function (Browser $browser) use ($volunteer, $shift, $first) {
            $browser->loginAs($volunteer)->visit(route('changes.show', $first->batch))->assertSee('Does the new time still work for you?');
            $this->assertAccessible($browser);

            $browser->press('Still works for me')->waitForText('Thanks. You\'re still on at the new time.');
            $this->assertAccessible($browser);

            $browser->visit(route('changes.show', $first->batch))->assertSee('You already said the new time works.');
            $this->assertAccessible($browser);

            // A newer time change on the same shift replaces the old one, so the old email's page says so.
            $this->noticeFor($volunteer, $shift);
            $browser->visit(route('changes.show', $first->batch))->assertSee('This shift has changed again since that email.');
            $this->assertAccessible($browser);
        });
    }

    public function test_the_change_notice_page_for_a_cancelled_shift_and_one_put_back_on(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $shift = $this->volunteersShift($volunteer);
        $ended = $this->noticeFor($volunteer, $shift);
        $ended->forceFill(['ended_at' => now()])->save();

        $this->browse(function (Browser $browser) use ($volunteer, $shift, $ended) {
            $browser->loginAs($volunteer)->visit(route('changes.show', $ended->batch))->assertSee('You\'re on this shift at its current time.');
            $this->assertAccessible($browser);

            $shift->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Power outage'])->save();
            $browser->visit(route('changes.show', $ended->batch))->assertSee('This shift was cancelled.');
            $this->assertAccessible($browser);
        });
    }

    public function test_home_and_the_shift_panel_ask_about_a_new_time(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $shift = $this->volunteersShift($volunteer);
        $this->noticeFor($volunteer, $shift);

        $this->browse(function (Browser $browser) use ($volunteer, $shift) {
            $browser->resize(1280, 900)->loginAs($volunteer)->visit('/')->assertSee('Still works for me');
            $this->assertAccessible($browser);

            $browser->visit(route('calendar.shift', $shift))->assertSee('Still works for me');
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
