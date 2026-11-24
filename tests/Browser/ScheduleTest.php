<?php

namespace Tests\Browser;

use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @return array<string, array{string, int}> */
    public static function computerWidths(): array
    {
        return [
            'volunteer at 1024' => [ExampleOrganizationSeeder::VOLUNTEER, 1024],
            'volunteer at 1280' => [ExampleOrganizationSeeder::VOLUNTEER, 1280],
            'coordinator at 1024' => [ExampleOrganizationSeeder::COORDINATOR, 1024],
            'coordinator at 1280' => [ExampleOrganizationSeeder::COORDINATOR, 1280],
        ];
    }

    #[DataProvider('computerWidths')]
    public function test_the_menu_stays_on_the_same_row_as_the_toolbar_on_a_computer(string $email, int $width): void
    {
        $user = User::where('email', $email)->firstOrFail();

        $this->browse(function (Browser $browser) use ($user, $email, $width) {
            $browser->resize($width, 900)->loginAs($user)->visit('/calendar')->assertVisible('.cal-grid')->assertVisible('.main-nav');
            $browser->screenshot('schedule-week-'.explode('@', $email)[0].'-'.$width);

            // The menu is beside the toolbar: to its right and starting within the toolbar's height, not on a row of its own.
            $box = $browser->script('const bar = document.querySelector(".cal-bar").getBoundingClientRect(), nav = document.querySelector(".main-nav").getBoundingClientRect(); return {barRight: bar.right, barTop: bar.top, barBottom: bar.bottom, navLeft: nav.left, navTop: nav.top, navBottom: nav.bottom}')[0];
            $this->assertGreaterThanOrEqual($box['barRight'] - 1, $box['navLeft']);
            $this->assertGreaterThanOrEqual($box['barTop'] - 1, $box['navTop']);
            $this->assertLessThanOrEqual($box['barBottom'] + 1, $box['navBottom']);
        });
    }

    public function test_a_short_week_does_not_scroll_past_its_end_on_a_computer(): void
    {
        $volunteer = User::where('email', ExampleOrganizationSeeder::VOLUNTEER)->firstOrFail();

        $this->browse(function (Browser $browser) use ($volunteer) {
            // A week years from now has no shifts, so the calendar is as short as it gets.
            $browser->resize(1280, 900)->loginAs($volunteer)->visit('/calendar?date='.now()->addYears(3)->toDateString())->assertVisible('.cal-grid');
            $browser->screenshot('schedule-week-empty-1280');

            // The hour grid itself is taller than the window, so the page scrolls. What matters is that the calendar's min-height adds nothing below it: the page ends where the calendar does.
            [$scrollHeight, $calBottom] = $browser->script('return [document.documentElement.scrollHeight, document.querySelector(".cal").getBoundingClientRect().bottom + window.scrollY]')[0];
            $this->assertEqualsWithDelta($scrollHeight, $calBottom, 1);

            // On a short calendar the minimum height fills the window exactly and no more, so there's nothing to scroll.
            $minHeight = $browser->script('return parseFloat(getComputedStyle(document.querySelector(".cal")).minHeight) + document.querySelector(".bar").getBoundingClientRect().height')[0];
            $this->assertLessThanOrEqual($browser->script('return window.innerHeight')[0] + 1, $minHeight);
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
