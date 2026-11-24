<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Enums\Role;
use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\MadeRegularNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class AddRegularScreenTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    protected function setUp(): void
    {
        parent::setUp();

        // The emails are deferred until after the response; calling an action directly has no response, so run them right away.
        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        $organization = Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 4]);
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

    /** Fridays, 3:30 to 6:30pm, with its shifts generated for the next four weeks (October 16, 23, 30 and November 6). */
    private function fridaySlot(int $peopleNeeded = 1, bool $rotation = false, ?Area $area = null, int $weekday = 5): WeeklySlot
    {
        $slot = WeeklySlot::factory()->create(['area_id' => ($area ?? $this->frontDesk)->id, 'weekday' => $weekday, 'start_time' => '15:30', 'end_time' => '18:30', 'people_needed' => $peopleNeeded, 'is_rotation' => $rotation]);
        app(GenerateShifts::class)->handle(Organization::current());

        return $slot;
    }

    private function addRegular(WeeklySlot $slot, string $name, string $every = 'weekly', string $from = '2026-10-09'): User
    {
        $user = User::factory()->create(['name' => $name]);

        if ($every === 'other') {
            app(AddRegular::class)->handle(null, $slot, $user, '2026-10-01', null, SlotFrequency::EveryOtherWeek, $from);
        } else {
            app(AddRegular::class)->handle(null, $slot, $user, $from);
        }

        return $user;
    }

    private function postRegular(WeeklySlot $slot, User $by, array $data)
    {
        return $this->actingAs($by)->post(route('calendar.slots.regulars.store', $slot), $data);
    }

    public function test_the_screen_offers_the_alternate_weeks_of_an_existing_regular(): void
    {
        $slot = $this->fridaySlot(peopleNeeded: 2);
        $this->addRegular($slot, 'Carol', every: 'other', from: '2026-10-09');

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.create', $slot))
            ->assertOk()
            ->assertSee('The weeks Carol is off')->assertSee('Fri Oct 16')
            ->assertSee('The same weeks as Carol')->assertSee('Fri Oct 23')
            ->assertSee('Starting a date I pick')
            ->assertSee('Fridays, 3:30 to 6:30pm')
            ->assertSee('Needs 2 people every week.')
            ->assertSee('Carol every other week from Oct 9');
    }

    public function test_the_screen_shows_no_design_note_captions(): void
    {
        $slot = $this->fridaySlot();

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.create', $slot))
            ->assertDontSee('After adding')->assertDontSee('When the slot is already full');
    }

    public function test_adding_a_regular_adds_them_to_shifts_logs_it_and_emails_them(): void
    {
        Notification::fake();
        $slot = $this->fridaySlot();
        $avery = User::factory()->create(['name' => 'Avery Stone']);

        $this->postRegular($slot, $this->coordinator(), ['user' => $avery->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23'])
            ->assertRedirect(route('calendar.week', ['date' => '2026-10-23']))
            ->assertSessionHas('status', 'Avery S. is now a regular, every week from Oct 23. We added them to 3 shifts already on the schedule. We\'ll email Avery S. to let them know.');

        Notification::assertSentTo($avery, MadeRegularNotification::class);
        $this->assertSame(3, Shift::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $avery->id))->count());
        $this->assertSame(1, ChangeLogEntry::query()->about($avery)->ofAction('regular.added')->count());
    }

    public function test_the_status_says_which_dates_overlap_another_shift_they_are_on(): void
    {
        Notification::fake();
        $slot = $this->fridaySlot();
        $avery = User::factory()->create(['name' => 'Avery Stone']);
        $coordinator = $this->coordinator();
        // Friday, October 30, 4 to 5pm, inside the slot's 3:30 to 6:30pm.
        $other = app(CreateOneTimeShift::class)->handle($coordinator, $this->hostedEvents, '2026-10-30', '16:00', '17:00', 1, 0, null);
        $other->assignments()->create(['user_id' => $avery->id, 'type' => 'regular']);

        $this->postRegular($slot, $coordinator, ['user' => $avery->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-16'])
            ->assertSessionHas('status', 'Avery S. is now a regular, every week from Oct 16. We added them to 3 shifts already on the schedule. Not added on Oct 30, which overlaps another shift they\'re on. We\'ll email Avery S. to let them know.');

        $later = app(CreateOneTimeShift::class)->handle($coordinator, $this->hostedEvents, '2026-11-06', '15:00', '16:00', 1, 0, null);
        $later->assignments()->create(['user_id' => $avery->id, 'type' => 'regular']);
        $alex = User::factory()->create(['name' => 'Alex Stone']);
        foreach ([$other, $later] as $shift) {
            $shift->assignments()->create(['user_id' => $alex->id, 'type' => 'shadow']);
        }
        $second = $this->fridaySlot(area: $this->hostedEvents);

        $this->postRegular($second, $coordinator, ['user' => $alex->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-16'])
            ->assertSessionHas('status', 'Alex S. is now a regular, every week from Oct 16. We added them to 2 shifts already on the schedule. Not added on Oct 30 and Nov 6, which overlap other shifts they\'re on. We\'ll email Alex S. to let them know.');
    }

    public function test_the_email_says_what_they_are_regular_for(): void
    {
        $slot = $this->fridaySlot();
        $avery = User::factory()->create(['name' => 'Avery Stone']);

        $this->postRegular($slot, $this->coordinator(), ['user' => $avery->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23']);

        $mail = (new MadeRegularNotification($slot->load('area'), SlotRegular::query()->sole(), 3))->toMail($avery);
        $this->assertSame("You're now the regular for Front desk on Fridays, 3:30 to 6:30pm, every week, starting Friday, October 23.", $mail->introLines[0]);
        $this->assertSame("We've put you on 3 shifts already on the schedule.", $mail->introLines[1]);
    }

    public function test_an_every_other_week_choice_becomes_the_anchor_date(): void
    {
        $slot = $this->fridaySlot(peopleNeeded: 2);
        $this->addRegular($slot, 'Carol', every: 'other', from: '2026-10-09');
        $avery = User::factory()->create(['name' => 'Avery Stone']);

        $this->postRegular($slot, $this->coordinator(), ['user' => $avery->id, 'frequency' => 'every_other_week', 'weeks' => '2026-10-16', 'starts_on' => '2026-10-14'])
            ->assertSessionHas('status', 'Avery S. is now a regular, every other week from Oct 16. We added them to 2 shifts already on the schedule. We\'ll email Avery S. to let them know.');

        $regular = SlotRegular::query()->where('user_id', $avery->id)->sole();
        $this->assertSame(SlotFrequency::EveryOtherWeek, $regular->frequency);
        $this->assertSame('2026-10-16', $regular->anchor_date->toDateString());
        $this->assertSame(['2026-10-16', '2026-10-30'], Shift::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $avery->id))->orderBy('starts_at')->get()->map(fn (Shift $shift) => $shift->local_date->toDateString())->all());
    }

    public function test_starting_a_date_i_pick_anchors_on_the_first_date(): void
    {
        $slot = $this->fridaySlot();
        $avery = User::factory()->create();

        $this->postRegular($slot, $this->coordinator(), ['user' => $avery->id, 'frequency' => 'every_other_week', 'weeks' => 'pick', 'starts_on' => '2026-10-23']);

        $this->assertSame('2026-10-23', SlotRegular::query()->sole()->anchor_date->toDateString());
    }

    public function test_every_other_week_needs_a_weekday_that_matches_the_slot(): void
    {
        $slot = $this->fridaySlot();
        $avery = User::factory()->create();

        $this->postRegular($slot, $this->coordinator(), ['user' => $avery->id, 'frequency' => 'every_other_week', 'weeks' => 'pick', 'starts_on' => '2026-10-22'])
            ->assertSessionHasErrors(['starts_on' => 'Pick a Friday.']);
        $this->postRegular($slot, $this->coordinator(), ['user' => $avery->id, 'frequency' => 'every_other_week', 'starts_on' => '2026-10-23'])
            ->assertSessionHasErrors('weeks');

        $this->assertSame(0, SlotRegular::query()->count());
    }

    public function test_adding_a_regular_over_people_needed_lists_the_dates_and_asks_first(): void
    {
        Notification::fake();
        Organization::current()->update(['weeks_ahead' => 12]);
        $slot = $this->fridaySlot(peopleNeeded: 1);
        app(GenerateShifts::class)->handle(Organization::current());
        $casey = $this->addRegular($slot, 'Casey', from: '2026-10-09');
        $robin = User::factory()->create(['name' => 'Robin Example']);
        $coordinator = $this->coordinator();
        $data = ['user' => $robin->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23'];
        $confirmUrl = route('calendar.slots.regulars.create', ['slot' => $slot, 'person' => $robin->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23', 'over' => 1]);

        $this->postRegular($slot, $coordinator, $data)
            ->assertRedirect($confirmUrl)
            ->assertSessionHasNoErrors();
        $this->assertSame(1, SlotRegular::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->about($robin)->count());

        $page = $this->actingAs($coordinator)->get($confirmUrl)->assertOk()
            ->assertSee('Friday, October 23 at 2 people. It needs 1.')
            ->assertSee('Friday, October 30 at 2 people. It needs 1.')
            ->assertSee('Friday, November 20 at 2 people. It needs 1.')
            ->assertDontSee('Friday, November 27 at 2 people')
            ->assertSee('Add Robin E. anyway')->assertSee('Change the dates')
            ->assertSee('name="over_confirmed" value="1"', false);
        $this->assertMatchesRegularExpression('/and \d+ more dates/', $page->getContent());

        $this->postRegular($slot, $coordinator, $data + ['over_confirmed' => 1])->assertRedirect(route('calendar.week', ['date' => '2026-10-23']));

        $this->assertSame(2, SlotRegular::query()->count());
        $this->assertSame(
            Shift::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $casey->id))->where('local_date', '>=', '2026-10-23')->count(),
            Shift::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $robin->id))->count(),
        );
        $this->assertSame(1, ChangeLogEntry::query()->about($robin)->ofAction('regular.added')->count());
    }

    public function test_the_confirm_step_goes_back_without_saving_and_ignores_a_slot_with_room(): void
    {
        $slot = $this->fridaySlot(peopleNeeded: 2);
        $this->addRegular($slot, 'Casey', from: '2026-10-09');
        $robin = User::factory()->create(['name' => 'Robin Example']);

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'person' => $robin->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23', 'over' => 1]))
            ->assertOk()->assertDontSee('anyway')->assertSee('Add Robin');
    }

    public function test_rotation_slots_take_no_regulars(): void
    {
        $slot = $this->fridaySlot(rotation: true);
        $avery = User::factory()->create();
        $coordinator = $this->coordinator();

        $this->postRegular($slot, $coordinator, ['user' => $avery->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23'])->assertSessionHasErrors('slot');
        $this->actingAs($coordinator)->get(route('calendar.slots.regulars.create', $slot))->assertNotFound();

        $this->assertSame(0, SlotRegular::query()->count());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('regular.added')->count());
    }

    public function test_the_action_refuses_a_rotation_and_a_person_who_is_turned_off(): void
    {
        $rotation = $this->fridaySlot(rotation: true);
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 5]);
        $off = User::factory()->create(['name' => 'Sam Rivera', 'deactivated_at' => now()]);

        try {
            app(AddRegular::class)->handle(null, $rotation, User::factory()->create(), '2026-10-23');
            $this->fail('A rotation took a regular.');
        } catch (DomainException) {
            // Expected.
        }

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Sam R. has been turned off');

        app(AddRegular::class)->handle(null, $slot, $off, '2026-10-23');
    }

    public function test_the_action_checks_the_actor_can_manage_the_area(): void
    {
        $slot = $this->fridaySlot();
        $lead = $this->coordinator(Role::AreaLead, $this->hostedEvents);

        $this->expectException(AuthorizationException::class);

        app(AddRegular::class)->handle($lead, $slot, User::factory()->create(), '2026-10-23');
    }

    public function test_an_area_lead_can_add_to_their_own_area_and_not_another(): void
    {
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);
        $own = $this->fridaySlot();
        $other = WeeklySlot::factory()->create(['area_id' => $this->hostedEvents->id, 'weekday' => 5]);
        $avery = User::factory()->create();

        $this->actingAs($lead)->get(route('calendar.slots.regulars.create', $own))->assertOk();
        $this->actingAs($lead)->get(route('calendar.slots.regulars.create', $other))->assertForbidden();
        $this->postRegular($other, $lead, ['user' => $avery->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23'])->assertForbidden();
        $this->postRegular($own, $lead, ['user' => $avery->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-23'])->assertSessionHasNoErrors();

        $this->assertSame(1, SlotRegular::query()->count());
    }

    public function test_search_matches_names_only_for_a_lead_and_shows_short_names(): void
    {
        $lead = $this->coordinator(Role::AreaLead, $this->frontDesk);
        $slot = $this->fridaySlot();
        User::factory()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
        User::factory()->create(['name' => 'Someone Else', 'email' => 'robin.secret@example.org']);
        User::factory()->create(['name' => 'Robin Gone', 'deactivated_at' => now()]);

        $this->actingAs($lead)->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'q' => 'Robin']))
            ->assertSee('Robin E.')->assertDontSee('Robin Example')->assertDontSee('robin@example.org')
            ->assertDontSee('Robin G.')->assertDontSee('Someone');
        $this->actingAs($lead)->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'q' => 'secret']))
            ->assertSee('Nobody matches');
        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'q' => 'secret']))
            ->assertSee('Someone Else')->assertSee('robin.secret@example.org');
    }

    public function test_a_chosen_person_is_carried_into_the_form(): void
    {
        $slot = $this->fridaySlot();
        $avery = User::factory()->create(['name' => 'Avery Stone']);

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'person' => $avery->id]))
            ->assertSee('Avery Stone')->assertSee('name="user" value="'.$avery->id.'"', false)->assertSee('Add Avery S.');
    }

    public function test_the_shift_panel_offers_add_a_regular_only_for_weekly_slots_that_are_not_rotations(): void
    {
        $coordinator = $this->coordinator();
        $slot = $this->fridaySlot();
        $rotation = $this->fridaySlot(rotation: true);
        $slotShift = $slot->shifts()->orderBy('starts_at')->first();
        $rotationShift = $rotation->shifts()->orderBy('starts_at')->first();
        $oneTime = app(CreateOneTimeShift::class)->handle($coordinator, $this->frontDesk, '2026-10-24', '10:00', '14:00', 1, 0, null);

        $this->actingAs($coordinator)->get(route('calendar.shift', $slotShift))
            ->assertSee('Add a regular')->assertSee(route('calendar.slots.regulars.create', [$slot, 'date' => $slotShift->local_date->toDateString()]), false);
        $this->actingAs($coordinator)->get(route('calendar.shift', $rotationShift))->assertDontSee('Add a regular');
        $this->actingAs($coordinator)->get(route('calendar.shift', $oneTime))->assertDontSee('Add a regular');
        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))->get(route('calendar.shift', $slotShift))->assertDontSee('Add a regular');
    }

    public function test_the_status_the_week_and_the_email_use_the_first_date_they_work(): void
    {
        Notification::fake();
        $slot = $this->fridaySlot(peopleNeeded: 2);
        $this->addRegular($slot, 'Carol', every: 'other', from: '2026-10-09');
        $avery = User::factory()->create(['name' => 'Avery Stone']);

        // Typed first date is Oct 16, but the same weeks as Carol start Oct 23.
        $this->postRegular($slot, $this->coordinator(), ['user' => $avery->id, 'frequency' => 'every_other_week', 'weeks' => '2026-10-23', 'starts_on' => '2026-10-16'])
            ->assertRedirect(route('calendar.week', ['date' => '2026-10-23']))
            ->assertSessionHas('status', 'Avery S. is now a regular, every other week from Oct 23. We added them to 2 shifts already on the schedule. We\'ll email Avery S. to let them know.');

        $regular = SlotRegular::query()->where('user_id', $avery->id)->sole();
        $this->assertSame('2026-10-23', $regular->firstWorkedDate()->toDateString());
        Notification::assertSentTo($avery, MadeRegularNotification::class, fn (MadeRegularNotification $mail) => $mail->startingOn === 'Friday, October 23');
    }

    public function test_the_action_returns_how_many_shifts_the_person_was_added_to(): void
    {
        $slot = $this->fridaySlot();

        $regular = app(AddRegular::class)->handle(null, $slot, User::factory()->create(), '2026-10-23');

        $this->assertSame(3, $regular->added_to_shifts);
    }

    public function test_week_options_start_on_the_earliest_date_that_is_today_or_later(): void
    {
        $coordinator = $this->coordinator();

        // Far in the past: Jan 2 is a Friday; Oct 16 is 41 weeks later.
        $far = $this->fridaySlot(peopleNeeded: 2);
        $this->addRegular($far, 'Carol', every: 'other', from: '2026-01-02');
        $this->actingAs($coordinator)->get(route('calendar.slots.regulars.create', $far))
            ->assertSee('value="2026-10-16"', false)->assertSee('value="2026-10-23"', false);

        // In the future: Nov 13 is in the same weeks as Oct 30 and Oct 16, so Oct 16 is the earliest.
        $future = $this->fridaySlot(peopleNeeded: 2);
        $this->addRegular($future, 'Dana', every: 'other', from: '2026-11-13');
        $response = $this->actingAs($coordinator)->get(route('calendar.slots.regulars.create', $future));
        $response->assertSee('Starting Fri Oct 16, then Oct 30, Nov 13')->assertSee('Starting Fri Oct 23, then Nov 6, Nov 20');
        $response->assertSeeInOrder(['The weeks Dana is off', 'Starting Fri Oct 23', 'The same weeks as Dana', 'Starting Fri Oct 16']);
    }

    public function test_a_week_option_can_start_today(): void
    {
        // Wednesday slot, and today is Wednesday, October 14.
        $slot = $this->fridaySlot(peopleNeeded: 2, weekday: 3);
        $this->addRegular($slot, 'Carol', every: 'other', from: '2026-10-14');

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.create', $slot))
            ->assertSeeInOrder(['The weeks Carol is off', 'Starting Wed Oct 21', 'The same weeks as Carol', 'Starting Wed Oct 14, then Oct 28, Nov 11']);
    }

    public function test_regulars_on_the_same_weeks_share_one_choice(): void
    {
        $slot = $this->fridaySlot(peopleNeeded: 3);
        $this->addRegular($slot, 'Carol', every: 'other', from: '2026-10-09');
        $this->addRegular($slot, 'Dana', every: 'other', from: '2026-10-23');

        $response = $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.create', $slot))
            ->assertSee('The weeks Carol and Dana are off')->assertSee('The same weeks as Carol and Dana');
        $this->assertSame(1, substr_count($response->getContent(), 'name="weeks" value="2026-10-16"'));
    }

    public function test_picking_a_person_keeps_what_was_typed(): void
    {
        $slot = $this->fridaySlot();
        $avery = User::factory()->create(['name' => 'Avery Stone']);
        $typed = ['starts_on' => '2026-10-30', 'ends_on' => '2026-11-06', 'frequency' => 'every_other_week', 'weeks' => 'pick'];
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'q' => 'Avery'] + $typed))
            ->assertSee('name="starts_on" value="2026-10-30"', false)
            ->assertSee('starts_on=2026-10-30', false)->assertSee('weeks=pick', false);

        $this->actingAs($coordinator)->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'person' => $avery->id] + $typed))
            ->assertSee('value="2026-10-30"', false)->assertSee('value="2026-11-06"', false)
            ->assertSee('name="frequency" value="every_other_week" checked', false)
            ->assertSee('ends_on=2026-11-06', false);
    }
}
