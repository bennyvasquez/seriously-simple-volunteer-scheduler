<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class AddToCalendarTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
    }

    private function coordinator(Role $role = Role::Coordinator, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function slotAttributes(array $overrides = []): array
    {
        return $overrides + ['weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'headcount' => 1, 'shadow_spots' => 0, 'frequency' => 'weekly', 'valid_from' => '2026-10-22', 'valid_until' => null, 'title' => null, 'is_rotation' => false];
    }

    private function panelPost(array $overrides = []): array
    {
        return $overrides + [
            'area' => $this->frontDesk->id, 'date' => '2026-10-21', 'start_time' => '18:00', 'end_time' => '21:00',
            'repeats' => 'none', 'headcount' => 1, 'shadow_spots' => 0,
        ];
    }

    public function test_a_one_time_shift_is_created_in_local_time_and_logged(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, '2026-10-24', '10:00', '14:00', 2, 0, 'Open house');

        $this->assertSame('2026-10-24 14:00:00', $shift->starts_at->format('Y-m-d H:i:s')); // 10am in Detroit is 2pm UTC in October.
        $this->assertSame('Open house', $shift->title);
        $this->assertTrue(ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.created')->exists());
    }

    public function test_a_shift_that_ends_before_it_starts_ends_the_next_day(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, '2026-10-24', '22:00', '01:00', 1, 0, null);

        $this->assertSame('2026-10-25 05:00:00', $shift->ends_at->format('Y-m-d H:i:s'));
    }

    public function test_a_one_time_shift_in_the_past_is_refused(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, '2026-10-13', '10:00', '14:00', 1, 0, null);
    }

    public function test_times_stay_local_across_daylight_saving(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-17 12:00', self::TIMEZONE));
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, $this->slotAttributes());

        $times = $slot->shifts()->orderBy('local_date')->get()->map(fn ($s) => $s->starts_at->setTimezone(self::TIMEZONE)->format('H:i'))->unique()->values()->all();

        $this->assertSame(['18:30'], $times);
        $this->assertSame(2, $slot->shifts()->get()->map(fn ($s) => $s->starts_at->format('H:i'))->unique()->count(), 'The UTC time moves by an hour when clocks change on November 1.');
    }

    public function test_a_one_time_shift_on_the_daylight_saving_change_day_lands_at_the_time_typed(): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, '2026-11-01', '18:30', '21:30', 1, 0, null);

        $this->assertSame('18:30', $shift->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
    }

    public function test_creating_a_slot_generates_its_shifts_now(): void
    {
        // Thursday, October 22, 9am: the 8-week window then holds eight Thursdays starting today.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', self::TIMEZONE));
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, $this->slotAttributes());

        $this->assertSame(8, $slot->shifts()->count());
        $entry = ChangeLogEntry::query()->forSubject($slot)->ofAction('slot.created')->firstOrFail();
        $this->assertSame(8, $entry->details['created_shifts']);
        $this->assertSame('2026-10-22', $slot->shifts()->orderBy('local_date')->first()->local_date->toDateString());
    }

    public function test_an_every_other_week_slot_is_anchored_on_its_first_date(): void
    {
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, $this->slotAttributes(['frequency' => 'every_other_week']));

        $this->assertSame('2026-10-22', $slot->anchor_date->toDateString());
        $this->assertSame(4, $slot->shifts()->count());
    }

    public function test_an_every_other_week_slot_must_start_on_its_weekday(): void
    {
        $this->expectException(ValidationException::class);

        app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, $this->slotAttributes(['frequency' => 'every_other_week', 'valid_from' => '2026-10-23']));
    }

    public function test_a_closure_keeps_a_date_from_getting_a_shift(): void
    {
        // Thursday, October 22, 9am: the 8-week window then holds eight Thursdays starting today.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', self::TIMEZONE));
        AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-10-29', 'ends_on' => '2026-10-29', 'reason' => 'Holiday']);

        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, $this->slotAttributes());

        $this->assertSame(7, $slot->shifts()->count());
        $this->assertFalse($slot->shifts()->where('local_date', '2026-10-29')->exists());
    }

    public function test_the_panel_creates_a_one_time_shift_and_returns_to_that_week(): void
    {
        $this->actingAs($this->coordinator())->post(route('calendar.add.store'), $this->panelPost(['date' => '2026-10-24', 'title' => 'Open house']))
            ->assertRedirect(route('calendar.week', ['date' => '2026-10-24']))
            ->assertSessionHas('status', 'Added the open house on Saturday, October 24.');

        $this->assertSame(1, Shift::query()->whereNull('weekly_slot_id')->count());
    }

    public function test_the_panel_creates_a_weekly_slot_when_repeats_is_chosen(): void
    {
        $this->actingAs($this->coordinator())->post(route('calendar.add.store'), $this->panelPost([
            'repeats' => 'weekly', 'fills_by' => 'regulars', 'ends' => 'never',
        ]))->assertRedirect();

        $this->assertSame(1, WeeklySlot::query()->where('weekday', 3)->count());
    }

    public function test_adding_a_slot_says_what_was_added(): void
    {
        // Thursday, October 22, 9am: the 8-week window then holds eight Thursdays starting today.
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', self::TIMEZONE));
        $this->actingAs($this->coordinator())->post(route('calendar.slots.store'), [
            'area' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'headcount' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'fills_by' => 'rotation', 'valid_from' => '2026-10-22', 'valid_until' => '',
        ])->assertRedirect(route('calendar.week', ['date' => '2026-10-22']))
            ->assertSessionHas('status', 'Added Thursdays, 6:30 to 9:30pm, starting Thursday, October 22. 8 shifts are on the calendar.');

        $this->assertTrue(WeeklySlot::query()->firstOrFail()->is_rotation);
    }

    public function test_choosing_a_repeat_reloads_the_panel_with_the_extra_questions(): void
    {
        $user = $this->coordinator();

        $this->actingAs($user)->get(route('calendar.add', ['date' => '2026-10-21']))
            ->assertOk()
            ->assertSee('Add to the calendar')
            ->assertSee('Every week on Wednesday')
            ->assertDontSee('Who fills it');

        $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost(['repeats' => 'weekly', 'refresh' => '1']))
            ->assertRedirect(route('calendar.add', ['date' => '2026-10-21', 'area' => $this->frontDesk->id]));

        $this->actingAs($user)->withSession(['_old_input' => $this->panelPost(['repeats' => 'weekly'])])
            ->get(route('calendar.add', ['date' => '2026-10-21']))
            ->assertSee('Who fills it')
            ->assertSee('No end date yet');

        $this->assertSame(0, WeeklySlot::query()->count());
    }

    public function test_the_panel_only_offers_areas_the_person_can_change(): void
    {
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);

        $this->actingAs($lead)->get(route('calendar.add'))
            ->assertOk()
            ->assertSee('Front desk')
            ->assertDontSee('<option value="'.$this->hostedEvents->id.'"', false);
    }

    public function test_a_lead_cannot_add_to_another_area(): void
    {
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);

        $this->actingAs($lead)->post(route('calendar.add.store'), $this->panelPost(['area' => $this->hostedEvents->id]))->assertForbidden();
        $this->actingAs($lead)->post(route('calendar.slots.store'), [
            'area' => $this->hostedEvents->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'headcount' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-22',
        ])->assertForbidden();

        $this->assertSame(0, Shift::query()->count());
        $this->assertSame(0, WeeklySlot::query()->count());
    }

    public function test_a_lead_can_add_to_their_own_area(): void
    {
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);

        $this->actingAs($lead)->post(route('calendar.add.store'), $this->panelPost())->assertRedirect();

        $this->assertSame(1, Shift::query()->count());
    }

    public function test_messages_are_in_plain_language(): void
    {
        $user = $this->coordinator();

        $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost(['end_time' => '18:00']))
            ->assertSessionHasErrors(['end_time' => 'A shift has to end at a different time than it starts.']);
        $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost(['date' => '2026-10-13']))
            ->assertSessionHasErrors(['date' => 'Pick a date from today on.']);
        $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost(['headcount' => 0]))
            ->assertSessionHasErrors(['headcount' => 'At least 1 person is needed.']);
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_an_end_before_the_start_means_the_next_day(): void
    {
        $this->actingAs($this->coordinator())->post(route('calendar.add.store'), $this->panelPost(['start_time' => '22:00', 'end_time' => '01:00']))
            ->assertSessionHasNoErrors()->assertRedirect();

        $this->assertSame(1, Shift::query()->count());
    }

    public function test_a_volunteer_cannot_reach_the_add_screens(): void
    {
        $this->actingAs(User::factory()->create())->get(route('calendar.add'))->assertForbidden();
    }

    public function test_the_slot_form_previews_the_first_dates_and_a_closure(): void
    {
        AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-26', 'reason' => 'Holiday']);

        $this->actingAs($this->coordinator())->get(route('calendar.slots.create', ['date' => '2026-10-22', 'area' => $this->frontDesk->id, 'start_time' => '18:30', 'end_time' => '21:30']))
            ->assertOk()
            ->assertSee('Add a weekly slot')
            ->assertSee('What this makes')
            ->assertSee('Thursdays, 6:30 to 9:30pm, every week, starting Thu Oct 22.')
            ->assertSee('Oct 22, Oct 29, Nov 5 and so on.')
            ->assertSee('Thu Nov 26 is skipped because Front desk is closed that day.');
    }

    public function test_the_edit_form_shows_the_slot_and_is_checked_against_its_area(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->hostedEvents->id, 'title' => 'Laser class helper']);
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);

        $this->actingAs($this->coordinator())->get(route('calendar.slots.edit', $slot))->assertOk()->assertSee('Laser class helper');
        $this->actingAs($lead)->get(route('calendar.slots.edit', $slot))->assertForbidden();
    }

    public function test_the_week_links_to_the_add_panel(): void
    {
        $this->actingAs($this->coordinator())->get(route('calendar.week', ['date' => '2026-10-21']))
            ->assertSee(route('calendar.add', ['date' => '2026-10-21']), false);
    }
}
