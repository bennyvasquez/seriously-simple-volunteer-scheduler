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
        return $overrides + ['weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0, 'frequency' => 'weekly', 'valid_from' => '2026-10-22', 'valid_until' => null, 'title' => null, 'is_rotation' => false];
    }

    private function panelPost(array $overrides = []): array
    {
        return $overrides + [
            'area' => $this->frontDesk->id, 'date' => '2026-10-21', 'start_time' => '18:00', 'end_time' => '21:00',
            'repeats' => 'none', 'people_needed' => 1, 'shadow_spots' => 0,
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

    /** Runs CreateOneTimeShift and returns the validation errors it refuses with. */
    private function oneTimeShiftErrors(string $date, string $start): array
    {
        try {
            app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, $date, $start, '21:00', 1, 0, null);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('The shift was created.');
    }

    public function test_a_one_time_shift_on_a_closed_day_is_refused(): void
    {
        AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-11-25', 'ends_on' => '2026-11-27', 'reason' => 'Thanksgiving']);

        $this->assertSame(['date' => ['Front desk is closed that day: Thanksgiving.']], $this->oneTimeShiftErrors('2026-11-26', '18:00'));
        $this->assertSame(0, Shift::query()->count());

        // Another area's closure doesn't matter.
        app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->hostedEvents, '2026-11-26', '18:00', '21:00', 1, 0, null);
        $this->assertSame(1, Shift::query()->count());
    }

    public function test_a_one_time_shift_that_has_already_started_is_refused(): void
    {
        // It's 9am on October 14.
        $this->assertSame(['start_time' => ["Pick a start time that hasn't passed yet."]], $this->oneTimeShiftErrors('2026-10-14', '08:30'));
        $this->assertSame(0, Shift::query()->count());

        app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, '2026-10-14', '09:30', '12:00', 1, 0, null);
        $this->assertSame(1, Shift::query()->count());
    }

    public function test_the_panel_shows_why_a_one_time_shift_is_refused(): void
    {
        AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-26', 'reason' => 'Thanksgiving']);

        $this->actingAs($this->coordinator())->followingRedirects()
            ->from(route('calendar.add', ['date' => '2026-11-26']))
            ->post(route('calendar.add.store'), $this->panelPost(['date' => '2026-11-26']))
            ->assertOk()
            ->assertSee('Front desk is closed that day: Thanksgiving.');
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
            'area' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0,
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
            'area' => $this->hostedEvents->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0,
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
        $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost(['people_needed' => 0]))
            ->assertSessionHasErrors(['people_needed' => 'At least 1 person is needed.']);
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

    private function slotFormPost(array $overrides = []): array
    {
        return $overrides + [
            'area' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-22', 'valid_until' => '',
        ];
    }

    public function test_the_slot_form_previews_the_changed_values_when_it_reloads(): void
    {
        $user = $this->coordinator();

        $this->actingAs($user)->followingRedirects()
            ->post(route('calendar.slots.store'), $this->slotFormPost(['refresh' => '1', 'weekday' => 5, 'valid_from' => '2026-10-23', 'start_time' => '09:00', 'end_time' => '12:00', 'frequency' => 'every_other_week']))
            ->assertOk()
            ->assertSee('Every other Friday, 9am to 12pm, starting Fri Oct 23.')
            ->assertSee('Oct 23, Nov 6, Nov 20 and so on.');

        $this->assertSame(0, WeeklySlot::query()->count());
    }

    public function test_the_preview_says_when_the_first_shifts_will_appear_if_the_slot_starts_beyond_the_window(): void
    {
        $this->actingAs($this->coordinator())
            ->get(route('calendar.slots.create', ['date' => '2027-01-07', 'area' => $this->frontDesk->id]))
            ->assertSee('The first shift is on Thursday, January 7. It appears on the calendar on Friday, November 13, since the calendar shows 8 weeks ahead.');

        // The window on Friday, November 13 runs to Thursday, January 7, so that's the day the save or the nightly run makes it.
        $this->travelTo(CarbonImmutable::parse('2026-11-13 09:00', self::TIMEZONE));
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, $this->slotAttributes(['valid_from' => '2027-01-07']));
        $this->assertSame(['2027-01-07'], $slot->shifts()->pluck('local_date')->map->toDateString()->all());
    }

    public function test_the_preview_counts_from_the_first_date_the_slot_runs_on(): void
    {
        // A Thursday slot whose first date is Monday, January 4: its first shift is still Thursday, January 7.
        $this->actingAs($this->coordinator())->followingRedirects()
            ->post(route('calendar.slots.store'), $this->slotFormPost(['refresh' => '1', 'valid_from' => '2027-01-04']))
            ->assertSee('The first shift is on Thursday, January 7. It appears on the calendar on Friday, November 13, since the calendar shows 8 weeks ahead.');
    }

    public function test_a_slot_saved_with_no_shifts_yet_links_to_itself_and_to_adding_a_regular(): void
    {
        $user = $this->coordinator();

        $response = $this->actingAs($user)->post(route('calendar.slots.store'), $this->slotFormPost(['valid_from' => '2027-01-07']));
        $slot = WeeklySlot::query()->sole();

        $response->assertRedirect(route('calendar.week', ['date' => '2027-01-07']));
        $this->actingAs($user)->get($response->headers->get('Location'))
            ->assertSee('Added Thursdays, 6:30 to 9:30pm, starting Thursday, January 7. No shifts are on the calendar yet.')
            ->assertSee('<a href="'.route('calendar.slots.edit', $slot).'">Edit the slot</a>', false)
            ->assertSee('<a href="'.route('calendar.slots.regulars.create', $slot).'">Add a regular</a>', false);

        // A slot with shifts on the calendar is reached from them, so the status has no links.
        $response = $this->actingAs($user)->post(route('calendar.slots.store'), $this->slotFormPost());
        $this->actingAs($user)->get($response->headers->get('Location'))->assertDontSee('Edit the slot');
    }

    public function test_the_preview_lists_only_dates_inside_the_window_and_skips_only_closures_inside_it(): void
    {
        // The window is Oct 14 to Dec 8. The closure on Dec 10 is outside it; the one on Nov 26 is inside.
        AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-12-10', 'ends_on' => '2026-12-10', 'reason' => 'Holiday']);

        $this->actingAs($this->coordinator())
            ->get(route('calendar.slots.create', ['date' => '2026-10-22', 'area' => $this->frontDesk->id]))
            ->assertSee('Oct 22, Oct 29, Nov 5 and so on.')
            ->assertDontSee('Dec 10');
    }

    public function test_the_preview_and_the_save_agree_on_how_many_shifts_a_slot_gets(): void
    {
        $slot = WeeklySlot::factory()->make(['valid_from' => '2026-10-22']);
        $window = $slot->datesWithin(CarbonImmutable::parse('2026-10-14', 'UTC'), CarbonImmutable::parse('2026-12-08', 'UTC'));

        $saved = app(CreateWeeklySlot::class)->handle($this->coordinator(), $this->frontDesk, $this->slotAttributes());

        $this->assertCount(7, $window);
        $this->assertSame(count($window), $saved->shifts()->count());
    }

    public function test_more_options_posts_and_redirects_without_a_token_in_the_url(): void
    {
        $response = $this->actingAs($this->coordinator())->post(route('calendar.add.store'), $this->panelPost(['more' => '1', 'title' => 'Laser helper', '_token' => 'abc']));

        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertStringStartsWith(route('calendar.slots.create'), $location);
        $this->assertStringNotContainsString('_token', $location);
        $this->assertStringContainsString('title=Laser%20helper', $location);
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_the_area_filter_survives_saving_reloading_and_more_options(): void
    {
        $user = $this->coordinator();
        $filter = ['areas' => [$this->frontDesk->id]];

        $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost($filter))
            ->assertRedirect(route('calendar.week', ['date' => '2026-10-21'] + $filter));
        $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost($filter + ['refresh' => '1']))
            ->assertRedirect(route('calendar.add', ['date' => '2026-10-21', 'area' => $this->frontDesk->id] + $filter));
        $this->assertStringContainsString('areas%5B0%5D='.$this->frontDesk->id, $this->actingAs($user)->post(route('calendar.add.store'), $this->panelPost($filter + ['more' => '1']))->headers->get('Location'));
        $this->actingAs($user)->post(route('calendar.slots.store'), $this->slotFormPost($filter))
            ->assertRedirect(route('calendar.week', ['date' => '2026-10-22'] + $filter));

        $this->actingAs($user)->get(route('calendar.add', ['date' => '2026-10-21'] + $filter))
            ->assertSee('name="areas[]" value="'.$this->frontDesk->id.'"', false);
    }

    public function test_the_panel_buttons_send_refresh_empty_so_a_restored_form_still_saves(): void
    {
        $html = $this->actingAs($this->coordinator())->get(route('calendar.add', ['date' => '2026-10-21']))->assertOk()->getContent();

        // A form the browser restored on back or forward can still have the hidden refresh set to 1; the buttons send it empty after it.
        $this->assertStringContainsString('<button type="submit" class="btn btn-primary" name="refresh" value="">Save</button>', $html);
        $this->assertMatchesRegularExpression('/<button type="submit" class="btn" name="refresh" value="" formaction="[^"]*more=1[^"]*">More options<\/button>/', $html);
        // The hidden input is found by its tag: once buttons share the name, form.elements.refresh is a list, not the input.
        $this->assertStringContainsString('<select name="repeats" id="repeats" data-refreshes-form>', $html);
        $this->assertStringContainsString("form.querySelector('input[name=refresh]')", file_get_contents(public_path('js/refresh-form.js')));
        $this->assertStringNotContainsString('elements.refresh', $html);

        // A restored form sends the hidden refresh and then the button's empty one, and PHP keeps the last; the panel then saves.
        $this->actingAs($this->coordinator())->post(route('calendar.add.store'), $this->panelPost(['refresh' => '']))
            ->assertRedirect(route('calendar.week', ['date' => '2026-10-21']));
        $this->assertSame(1, Shift::query()->count());
    }

    public function test_more_options_from_a_restored_panel_still_opens_the_full_form(): void
    {
        $response = $this->actingAs($this->coordinator())->post(route('calendar.add.store', ['more' => 1]), $this->panelPost(['refresh' => '', 'title' => 'Laser helper']));

        $this->assertStringStartsWith(route('calendar.slots.create'), $response->headers->get('Location'));
        $this->assertSame(0, Shift::query()->count());
    }

    public function test_the_slot_form_save_button_sends_refresh_empty_and_dates_and_times_never_reload(): void
    {
        $html = $this->actingAs($this->coordinator())->get(route('calendar.slots.create', ['date' => '2026-10-22']))->assertOk()->getContent();

        $this->assertStringContainsString('<button type="submit" class="btn btn-primary" name="refresh" value="">Save the slot</button>', $html);
        // Typing a time or date one digit at a time would reload the page mid-entry, so only selects and radios reload it.
        $this->assertDoesNotMatchRegularExpression('/<input type="(time|date)"[^>]*data-refreshes-form/', $html);
        $this->assertMatchesRegularExpression('/<select name="weekday"[^>]*data-refreshes-form/', $html);
        // The preview button is always there, not only with JavaScript off.
        $this->assertStringContainsString('<button type="submit" class="btn" name="refresh" value="1">Update the preview</button>', $html);
        $this->assertStringNotContainsString('<noscript>', $html);

        $this->actingAs($this->coordinator())->post(route('calendar.slots.store'), $this->slotFormPost(['refresh' => '']))
            ->assertRedirect(route('calendar.week', ['date' => '2026-10-22']));
        $this->assertSame(1, WeeklySlot::query()->count());
    }

    public function test_a_slot_describes_itself(): void
    {
        $weekly = WeeklySlot::factory()->make(['start_time' => '18:30', 'end_time' => '21:30']);
        $other = WeeklySlot::factory()->everyOtherWeek('2026-10-21')->make(['start_time' => '11:30', 'end_time' => '14:30']);

        $this->assertSame('Thursdays, 6:30 to 9:30pm', $weekly->describe());
        $this->assertSame('Every other Wednesday, 11:30am to 2:30pm', $other->describe());
    }
}
