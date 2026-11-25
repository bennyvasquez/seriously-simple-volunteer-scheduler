<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RemovePerson;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\EndRegular;
use App\Actions\Scheduling\GenerateShifts;
use App\ChangeLog\Describe;
use App\Enums\Role;
use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\RegularEndedNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class EndRegularTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $robin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->robin = User::factory()->create(['name' => 'Robin Example']);
    }

    private function coordinator(Role $role = Role::Coordinator, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** Front desk, 6:30 to 9:30pm on the given weekday (4 is Thursday), with its shifts generated. */
    private function slot(int $weekday = 4, int $peopleNeeded = 2): WeeklySlot
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => $weekday, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => $peopleNeeded]);
        app(GenerateShifts::class)->handle(Organization::current());

        return $slot;
    }

    private function regular(WeeklySlot $slot, string $startsOn, ?User $user = null, ?string $endsOn = null): SlotRegular
    {
        return app(AddRegular::class)->handle(null, $slot, $user ?? $this->robin, $startsOn, $endsOn);
    }

    /** @return list<string> */
    private function datesFor(User $user): array
    {
        return Shift::query()
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('starts_at')->get()
            ->map(fn (Shift $shift) => $shift->local_date->toDateString())
            ->all();
    }

    private function shiftOn(WeeklySlot $slot, string $date): Shift
    {
        return $slot->shifts()->where('local_date', $date)->firstOrFail();
    }

    public function test_ending_a_regular_removes_them_from_later_shifts_only(): void
    {
        Notification::fake();
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');
        $this->shiftOn($slot, '2026-11-12')->update(['cancelled_at' => now(), 'cancel_reason' => 'Power outage']);
        $laterShiftIds = $slot->shifts()->where('local_date', '>', '2026-10-29')->orderBy('starts_at')->pluck('id')->all();
        $coordinator = $this->coordinator();

        $this->assertGreaterThanOrEqual(5, count($laterShiftIds));

        $ended = app(EndRegular::class)->handle($coordinator, $regular, '2026-10-29');

        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($this->robin));
        $this->assertSame('2026-10-29', $regular->fresh()->ends_on->toDateString());
        $this->assertSame(count($laterShiftIds), $ended->removed_from_shifts);

        $entry = ChangeLogEntry::query()->about($this->robin)->ofAction('regular.ended')->sole();
        $this->assertSame(['ends_on' => ['old' => null, 'new' => '2026-10-29']], $entry->changes);
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertSame($regular->id, $entry->subject_id);
        $this->assertSame($slot->id, $entry->details['weekly_slot_id']);
        $this->assertSame($laterShiftIds, $entry->details['removed_from_shift_ids']);
        $this->assertSame('Ended Robin Example\'s time as the regular for Front desk, Thursdays, 6:30 to 9:30pm', Describe::entry($entry->fresh(), $coordinator));
        $this->assertSame([], $entry->details['past_shift_ids']);

        $removed = ChangeLogEntry::query()->about($this->robin)->ofAction('assignment.removed')->get();
        $this->assertCount(count($laterShiftIds), $removed);
        $this->assertSame($laterShiftIds, $removed->pluck('subject_id')->sort()->values()->all());
        $this->assertTrue($removed->every(fn (ChangeLogEntry $row) => $row->details['via'] === 'regular.ended' && $row->details['slot_regular_id'] === $regular->id));

        // Emailed once, listing the future shifts that weren't cancelled.
        Notification::assertSentToTimes($this->robin, RegularEndedNotification::class, 1);
        Notification::assertSentTo($this->robin, RegularEndedNotification::class, fn (RegularEndedNotification $notification) => count($notification->shifts) === count($laterShiftIds) - 1);

        // The next day's generation doesn't put Robin back.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', self::TIMEZONE));
        app(GenerateShifts::class)->handle(Organization::current());
        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($this->robin));
    }

    public function test_a_past_last_date_needs_the_confirmation(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00', self::TIMEZONE));
        $slot = $this->slot(3);
        $regular = $this->regular($slot, '2026-09-02');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        app(GenerateShifts::class)->handle(Organization::current());
        $coordinator = $this->coordinator();
        $before = Assignment::query()->where('user_id', $this->robin->id)->count();

        $past = app(EndRegular::class)->pastShifts($regular, '2026-09-23');
        $this->assertSame(['2026-09-30', '2026-10-07'], $past->map(fn (Shift $shift) => $shift->local_date->toDateString())->all());

        try {
            app(EndRegular::class)->handle($coordinator, $regular, '2026-09-23');
            $this->fail('Ending in the past without confirming should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('Confirm the past shifts first.', $e->getMessage());
        }

        $this->assertSame($before, Assignment::query()->where('user_id', $this->robin->id)->count());
        $this->assertNull($regular->fresh()->ends_on);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('regular.ended')->count());

        app(EndRegular::class)->handle($coordinator, $regular, '2026-09-23', true);

        $this->assertSame(['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23'], $this->datesFor($this->robin));
        $entry = ChangeLogEntry::query()->ofAction('regular.ended')->sole();
        $this->assertSame($past->modelKeys(), $entry->details['past_shift_ids']);
        $this->assertSame(2 + $slot->shifts()->where('local_date', '>', '2026-10-07')->count(), count($entry->details['removed_from_shift_ids']));

        $futureCount = $slot->shifts()->where('starts_at', '>', now())->count();
        Notification::assertSentTo($this->robin, RegularEndedNotification::class, fn (RegularEndedNotification $notification) => count($notification->shifts) === $futureCount && collect($notification->shifts)->every(fn (string $line) => ! str_contains($line, 'September') && ! str_contains($line, 'October 7')));
    }

    public function test_ending_a_regular_who_has_not_started_yet(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-11-05');
        $this->assertNotEmpty($this->datesFor($this->robin));

        app(EndRegular::class)->handle($this->coordinator(), $regular, '2026-10-20');

        $this->assertSame('2026-11-04', $regular->fresh()->ends_on->toDateString());
        $this->assertSame([], $this->datesFor($this->robin));
        $this->assertNull(RemovePerson::refusal($this->robin));
    }

    public function test_an_every_other_week_regular_leaves_their_partner_alone(): void
    {
        $slot = $this->slot();
        $casey = User::factory()->create(['name' => 'Casey Example']);
        $robinRegular = app(AddRegular::class)->handle(null, $slot, $this->robin, '2026-10-14', null, SlotFrequency::EveryOtherWeek, '2026-10-15');
        app(AddRegular::class)->handle(null, $slot, $casey, '2026-10-14', null, SlotFrequency::EveryOtherWeek, '2026-10-22');
        $caseyBefore = $this->datesFor($casey);

        app(EndRegular::class)->handle($this->coordinator(), $robinRegular, '2026-10-22');

        $this->assertSame(['2026-10-15'], $this->datesFor($this->robin));
        $this->assertSame($caseyBefore, $this->datesFor($casey));
        $this->assertSame(0, ChangeLogEntry::query()->about($casey)->ofAction('assignment.removed')->count());
    }

    public function test_end_date_rules(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-14', null, '2026-11-12');
        $coordinator = $this->coordinator();

        try {
            app(EndRegular::class)->handle($coordinator, $regular, '2026-11-19');
            $this->fail('A later date should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('Robin E. already stops after Thursday, November 12. To extend it, add them as a regular again.', $e->getMessage());
        }

        try {
            app(EndRegular::class)->handle($coordinator, $regular, '2026-11-12');
            $this->fail('The same date should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('Robin E. already stops after Thursday, November 12.', $e->getMessage());
        }

        $this->assertSame('2026-11-12', $regular->fresh()->ends_on->toDateString());
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('regular.ended')->count());

        app(EndRegular::class)->handle($coordinator, $regular, '2026-10-22');
        $this->assertSame('2026-10-22', $regular->fresh()->ends_on->toDateString());

        // An end date that has already passed can be moved earlier too.
        $old = SlotRegular::query()->create(['weekly_slot_id' => $slot->id, 'user_id' => User::factory()->create(['name' => 'Casey Example'])->id, 'frequency' => SlotFrequency::Weekly, 'starts_on' => '2026-09-01', 'ends_on' => '2026-10-07']);
        app(EndRegular::class)->handle($coordinator, $old, '2026-09-23');
        $this->assertSame('2026-09-23', $old->fresh()->ends_on->toDateString());
    }

    public function test_a_regular_added_again_later_is_not_removed_by_ending_the_first(): void
    {
        $slot = $this->slot();
        $first = $this->regular($slot, '2026-10-14', null, '2026-10-29');
        $this->regular($slot, '2026-11-12');

        app(EndRegular::class)->handle($this->coordinator(), $first, '2026-10-15');

        $this->assertContains('2026-11-12', $this->datesFor($this->robin));
        $this->assertSame(['2026-10-15', '2026-11-12'], array_slice($this->datesFor($this->robin), 0, 2));
    }

    public function test_only_people_who_manage_the_area_can_end_a_regular(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');

        try {
            app(EndRegular::class)->handle($this->coordinator(Role::AreaLead, $this->hostedEvents), $regular, '2026-10-22');
            $this->fail('A lead of another area should be refused.');
        } catch (AuthorizationException) {
            $this->assertNull($regular->fresh()->ends_on);
        }

        app(EndRegular::class)->handle($this->coordinator(Role::AreaLead, $this->frontDesk), $regular, '2026-10-22');
        $this->assertSame('2026-10-22', $regular->fresh()->ends_on->toDateString());
    }

    public function test_the_page_defaults_to_the_day_before_the_shift_and_lists_nothing_to_confirm(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.end', [$slot, $regular, 'date' => '2026-10-29']))
            ->assertOk()
            ->assertSee('End Robin Example', false)
            ->assertSee('time as a regular', false)
            ->assertSee('Thursdays, 6:30 to 9:30pm')
            ->assertSee('Last date they work')
            ->assertSee('value="2026-10-28"', false)
            ->assertSee('They\'re removed from every shift of this slot after that date. They\'re emailed about future shifts.')
            ->assertSee('End their time as a regular');
    }

    public function test_a_last_date_sent_empty_comes_back_empty(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.end', [$slot, $regular, 'date' => '2026-10-29', 'last_date' => '']))
            ->assertOk()
            ->assertDontSee('value="2026-10-28"', false);
    }

    public function test_the_page_is_not_for_a_regular_of_another_slot_or_for_people_who_cannot_manage_it(): void
    {
        $slot = $this->slot();
        $other = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 2]);
        $regular = $this->regular($slot, '2026-10-15');

        $this->actingAs($this->coordinator())->get(route('calendar.slots.regulars.end', [$other, $regular]))->assertNotFound();
        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))->get(route('calendar.slots.regulars.end', [$slot, $regular]))->assertForbidden();
        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-10-22'])->assertForbidden();
        $this->assertNull($regular->fresh()->ends_on);
    }

    public function test_posting_a_future_date_ends_the_regular_and_goes_back_to_the_shift(): void
    {
        Notification::fake();
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');
        $shift = $this->shiftOn($slot, '2026-10-29');
        $removed = $slot->shifts()->where('local_date', '>', '2026-10-28')->count();

        $this->actingAs($this->coordinator())->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-10-28', 'date' => '2026-10-29'])
            ->assertRedirect(route('calendar.shift', $shift))
            ->assertSessionHas('status', 'Robin E. is no longer a regular after Wednesday, October 28. They were removed from '.$removed.' shifts.');

        $this->assertSame('2026-10-28', $regular->fresh()->ends_on->toDateString());
        Notification::assertSentTo($this->robin, RegularEndedNotification::class);
    }

    public function test_posting_a_past_date_shows_the_confirm_step_before_saving(): void
    {
        Notification::fake();
        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00', self::TIMEZONE));
        $slot = $this->slot(3);
        $regular = $this->regular($slot, '2026-09-02');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        app(GenerateShifts::class)->handle(Organization::current());
        $coordinator = $this->coordinator();
        $before = Assignment::query()->where('user_id', $this->robin->id)->count();

        $this->actingAs($coordinator)->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-09-23', 'date' => '2026-10-14'])
            ->assertOk()
            ->assertSee('Robin E. is on 2 past shifts after Wednesday, September 23. Ending their time as a regular removes them from those too, so they don\'t count as worked:', false)
            ->assertSee('Wednesday, September 30')
            ->assertSee('Wednesday, October 7')
            ->assertSee('name="past_confirmed" value="1"', false)
            ->assertSee('Remove Robin E. from 2 past shifts and end their time as a regular')
            ->assertSee('Change the date');

        $this->assertSame($before, Assignment::query()->where('user_id', $this->robin->id)->count());
        $this->assertNull($regular->fresh()->ends_on);

        $this->actingAs($coordinator)->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-09-23', 'date' => '2026-10-14', 'past_confirmed' => '1'])
            ->assertRedirect(route('calendar.shift', $this->shiftOn($slot, '2026-10-14')));

        $this->assertSame('2026-09-23', $regular->fresh()->ends_on->toDateString());
        $this->assertSame(['2026-09-02', '2026-09-09', '2026-09-16', '2026-09-23'], $this->datesFor($this->robin));
    }

    public function test_a_single_past_shift_reads_in_the_singular(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-02 09:00', self::TIMEZONE));
        $slot = $this->slot(3);
        $regular = $this->regular($slot, '2026-09-02');
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));

        $this->actingAs($this->coordinator())->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-10-06', 'date' => '2026-10-14'])
            ->assertOk()
            ->assertSee('Robin E. is on 1 past shift after Tuesday, October 6. Ending their time as a regular removes them from it too, so it doesn\'t count as worked:', false)
            ->assertSee('Remove Robin E. from 1 past shift and end their time as a regular');
    }

    public function test_a_bad_date_goes_back_to_the_page_with_a_message(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15', null, '2026-11-12');
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '', 'date' => '2026-10-29'])
            ->assertRedirect()->assertSessionHasErrors('last_date');

        $this->actingAs($coordinator)->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-11-19', 'date' => '2026-10-29'])
            ->assertRedirect()->assertSessionHasErrors(['last_date' => 'Robin E. already stops after Thursday, November 12. To extend it, add them as a regular again.']);
    }

    public function test_the_shift_panel_links_to_ending_a_regular_for_people_who_can_manage_the_schedule(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');
        $casey = User::factory()->create(['name' => 'Casey Example']);
        $shift = $this->shiftOn($slot, '2026-10-22');
        $shift->assignments()->create(['user_id' => $casey->id, 'type' => 'regular']);
        $link = route('calendar.slots.regulars.end', [$slot, $regular, 'date' => '2026-10-22']);

        $this->actingAs($this->coordinator())->get(route('calendar.shift', $shift))
            ->assertOk()
            ->assertSee('End regular')
            ->assertSee(e($link), false);

        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))->get(route('calendar.shift', $shift))
            ->assertDontSee('End regular');
    }

    public function test_ending_a_regular_before_their_start_reads_as_no_longer_a_regular(): void
    {
        Notification::fake();
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-11-05');
        $shift = $this->shiftOn($slot, '2026-10-29');
        $removed = $slot->shifts()->where('local_date', '>=', '2026-11-05')->count();

        $this->actingAs($this->coordinator())->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-10-20', 'date' => '2026-10-29'])
            ->assertRedirect(route('calendar.shift', $shift))
            ->assertSessionHas('status', 'Robin E. is no longer a regular. They were removed from '.$removed.' shifts.');

        Notification::assertSentTo($this->robin, RegularEndedNotification::class, function (RegularEndedNotification $notification) {
            $this->assertSame('You\'re no longer the regular for the Front desk shift on Thursdays.', $notification->toMail($this->robin)->introLines[0]);

            return true;
        });
    }

    public function test_end_own_hands_each_future_shift_to_the_callback_and_sends_no_email(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');
        Notification::fake();
        $cancelled = $this->shiftOn($slot, '2026-11-12');
        $cancelled->update(['cancelled_at' => now(), 'cancel_reason' => 'Power outage']);
        $future = $slot->shifts()->where('local_date', '>', '2026-10-28')->whereNull('cancelled_at')->orderBy('starts_at')->pluck('id')->all();
        $handed = [];

        $ended = app(EndRegular::class)->endOwn($this->robin, $regular, '2026-10-28', function (Shift $shift, Assignment $assignment) use (&$handed) {
            $this->assertSame($this->robin->id, $assignment->user_id);
            $handed[] = $shift->id;
        });

        $this->assertSame($future, $handed);
        $this->assertSame('2026-10-28', $ended->fresh()->ends_on->toDateString());
        // The callback removes nothing here, so Robin is still on those; the cancelled shift is cleared like handle() clears it.
        $this->assertFalse($cancelled->assignments()->exists());
        $this->assertSame(count($future) + 2, count($this->datesFor($this->robin)));

        $entry = ChangeLogEntry::query()->about($this->robin)->ofAction('regular.ended')->sole();
        $this->assertSame($this->robin->id, $entry->actor_id);
        $this->assertSame(['ends_on' => ['old' => null, 'new' => '2026-10-28']], $entry->changes);
        $removed = ChangeLogEntry::query()->about($this->robin)->ofAction('assignment.removed')->sole();
        $this->assertSame($cancelled->id, $removed->subject_id);
        $this->assertSame('regular.ended', $removed->details['via']);
        Notification::assertNothingSent();
    }

    public function test_end_own_refuses_someone_elses_regular(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');
        $casey = User::factory()->create(['name' => 'Casey Example']);

        try {
            app(EndRegular::class)->endOwn($casey, $regular, '2026-10-28', fn () => $this->fail('Nothing should be handed on.'));
            $this->fail('Someone else\'s regular should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('That isn\'t your weekly slot.', $e->getMessage());
        }

        $this->assertNull($regular->fresh()->ends_on);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('regular.ended')->count());
    }

    public function test_end_own_refuses_a_past_date(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-14');

        try {
            app(EndRegular::class)->endOwn($this->robin, $regular, '2026-10-12', fn () => null);
            $this->fail('A last date before yesterday should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('Pick a date from today on.', $e->getMessage());
        }

        $this->assertNull($regular->fresh()->ends_on);

        // Yesterday as the last date gives up the slot from today.
        app(EndRegular::class)->endOwn($this->robin, $regular, '2026-10-13', fn () => null);
        $this->assertSame('2026-10-13', $regular->fresh()->ends_on->toDateString());
    }

    public function test_end_own_refuses_an_unreadable_date(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');

        try {
            app(EndRegular::class)->endOwn($this->robin, $regular, 'tomorrow', fn () => $this->fail('Nothing should be handed on.'));
            $this->fail('An unreadable date should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('Pick a date.', $e->getMessage());
        }

        $this->assertNull($regular->fresh()->ends_on);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('regular.ended')->count());
    }

    public function test_end_own_speaks_to_the_person_when_they_already_stop_earlier(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15', null, '2026-11-12');

        $this->expectExceptionObject(new DomainException('You already stop after Thursday, November 12.'));

        app(EndRegular::class)->endOwn($this->robin, $regular, '2026-11-18', fn () => null);
    }

    public function test_the_status_leaves_out_the_shifts_sentence_when_there_were_none(): void
    {
        $slot = $this->slot();
        $regular = $this->regular($slot, '2026-10-15');
        $shift = $this->shiftOn($slot, '2026-10-29');

        // Shifts are on the calendar through December 8, so none come after December 31.
        $this->actingAs($this->coordinator())->post(route('calendar.slots.regulars.end.store', [$slot, $regular]), ['last_date' => '2026-12-31', 'date' => '2026-10-29'])
            ->assertRedirect(route('calendar.shift', $shift))
            ->assertSessionHas('status', 'Robin E. is no longer a regular after Thursday, December 31.');
    }
}
