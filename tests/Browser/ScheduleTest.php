<?php

namespace Tests\Browser;

use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\DuskTestCase;

/**
 * The Schedule tab in a real browser (spec section 6): a volunteer opens it from the menu, sees the read-only week on a computer and the list by day on a phone, and follows "Take this shift" from a shift with an open place.
 */
class ScheduleTest extends DuskTestCase
{
    /** The first coming shift of a weekly slot with no regular, so it has an open place the example volunteer can take. */
    private function openShift(): Shift
    {
        $slot = WeeklySlot::query()->whereDoesntHave('regulars')->firstOrFail();

        return Shift::query()->where('weekly_slot_id', $slot->id)->where('starts_at', '>', now())->orderBy('starts_at')->firstOrFail();
    }

    public function test_a_volunteer_opens_the_schedule_from_the_menu_and_takes_a_shift(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $shift = $this->openShift();

        $this->browse(function (Browser $browser) use ($volunteer, $shift) {
            $browser->resize(1280, 900)->loginAs($volunteer)->visit('/');
            $browser->with('.main-nav', fn (Browser $menu) => $menu->assertSee('Home')->assertSee('Me')->clickLink('Schedule'))
                ->assertPathIs('/calendar')
                ->assertVisible('.cal-grid')
                ->assertMissing('.cal-list .ev')
                ->assertDontSee('Needs attention')
                ->assertDontSee('+ Add');

            // The week grid on a computer, and the shift panel with its Take button.
            $browser->visit(route('calendar.shift', $shift))->assertVisible('.cal-panel')->assertSee('Take this shift')
                ->clickLink('Take this shift')
                ->waitForText('How long do you want it?')
                ->assertPathIs(route('shifts.take', $shift, false));
        });
    }

    public function test_a_volunteer_sees_the_list_by_day_on_a_phone(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            $browser->resize(390, 844)->loginAs($volunteer)->visit('/calendar')
                ->assertVisible('.cal-list')
                ->assertMissing('.cal-grid-wrap')
                ->assertVisible('.main-nav');
            $browser->screenshot('schedule-phone-list');

            // Scrolled to the end, the last block is above the fixed menu (the page has room for it below the content).
            $browser->script('window.scrollTo(0, document.body.scrollHeight)');
            $browser->screenshot('schedule-phone-list-end');
            $this->assertLessThanOrEqual(
                $browser->script('return document.querySelector(".main-nav").getBoundingClientRect().top')[0],
                $browser->script('return document.querySelector(".cal-key").getBoundingClientRect().bottom')[0],
            );

            $this->assertSame('fixed', $browser->script('return getComputedStyle(document.querySelector(".main-nav")).position')[0]);
        });
    }

    public function test_phone_screenshots_of_the_release_and_take_pages(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();
        $own = ExampleOrganizationSeeder::releasableShiftOf($volunteer);
        $open = $this->openShift();

        $this->browse(function (Browser $browser) use ($volunteer, $own, $open) {
            $browser->resize(390, 844)->loginAs($volunteer)
                ->visit(route('shifts.release', $own))->assertSee('Which shifts can\'t you make?')->assertVisible('.main-nav')->screenshot('release-phone')
                ->visit(route('shifts.take', $open))->assertSee('How long do you want it?')->assertVisible('.main-nav')->screenshot('take-phone');
        });
    }
}
