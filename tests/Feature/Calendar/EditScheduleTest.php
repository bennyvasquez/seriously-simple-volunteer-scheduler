<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\UpdateShift;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\ShiftCancelledNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class EditScheduleTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();

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

    /** A weekly Thursday slot starting tomorrow's week (Thursday, October 15), with its 8 shifts generated. */
    private function thursdaySlot(string $start, string $end, string $frequency = 'weekly', ?Area $area = null): WeeklySlot
    {
        return app(CreateWeeklySlot::class)->handle($this->coordinator(), $area ?? $this->frontDesk, [
            'weekday' => 4, 'start_time' => $start, 'end_time' => $end, 'headcount' => 1, 'shadow_spots' => 0,
            'frequency' => $frequency, 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
    }

    /** The slot's current values, in the keys UpdateWeeklySlot takes. */
    private function slotAttributes(WeeklySlot $slot): array
    {
        return [
            'weekday' => $slot->weekday, 'start_time' => substr($slot->start_time, 0, 5), 'end_time' => substr($slot->end_time, 0, 5),
            'headcount' => $slot->headcount, 'shadow_spots' => $slot->shadow_spots, 'frequency' => $slot->frequency->value,
            'valid_from' => $slot->valid_from->toDateString(), 'valid_until' => $slot->valid_until?->toDateString(),
            'title' => $slot->title, 'is_rotation' => $slot->is_rotation,
        ];
    }

    private function shiftWithPerson(): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator(), $this->frontDesk, '2026-10-22', '18:30', '21:30', 1, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => User::factory()->create()->id, 'type' => AssignmentType::Regular]);

        return $shift;
    }

    private function localStart(Shift $shift): string
    {
        return $shift->starts_at->setTimezone(self::TIMEZONE)->format('H:i');
    }

    public function test_editing_a_slot_updates_only_unstaffed_future_shifts(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $staffed = $slot->shifts()->orderBy('local_date')->first();
        app(PutOnShift::class)->handle($this->coordinator(), $staffed, User::factory()->create(), AssignmentType::Regular);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['start_time' => '18:00', 'end_time' => '21:00'] + $this->slotAttributes($slot));

        $this->assertTrue($result->keptShifts->contains($staffed));
        $this->assertSame('18:30', $staffed->fresh()->starts_at->setTimezone('America/Detroit')->format('H:i'));
        $this->assertSame('18:00', $slot->shifts()->whereKeyNot($staffed->id)->first()->starts_at->setTimezone('America/Detroit')->format('H:i'));
        $this->assertSame(7, $result->updatedShifts);

        $entry = ChangeLogEntry::query()->ofAction('slot.updated')->sole();
        $this->assertSame(7, $entry->details['updated_shifts']);
        $this->assertSame([$staffed->id], $entry->details['kept_shift_ids']);
        $this->assertSame(['18:30:00', '18:00:00'], [$entry->changes['start_time']['old'], $entry->changes['start_time']['new']]);
    }

    public function test_editing_a_slot_never_touches_started_or_cancelled_shifts(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shifts = $slot->shifts()->orderBy('local_date')->get();
        app(CancelShift::class)->handle($this->coordinator(), $shifts[1], 'Power outage');
        // A week later, the first shift has started.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', self::TIMEZONE));

        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['headcount' => 3, 'title' => 'Greeter'] + $this->slotAttributes($slot));

        $this->assertSame(1, $shifts[0]->fresh()->headcount);
        $this->assertSame(1, $shifts[1]->fresh()->headcount);
        $this->assertSame('Power outage', $shifts[1]->fresh()->cancel_reason);
        $this->assertSame(3, $shifts[2]->fresh()->headcount);
        $this->assertSame('Greeter', $shifts[2]->fresh()->title);
    }

    public function test_a_shift_with_people_on_it_cannot_be_edited_yet(): void
    {
        $shift = $this->shiftWithPerson();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Someone is on this shift. Editing shifts with people on them comes soon; for now, cancel this date and add a one-time shift.');
        app(UpdateShift::class)->handle($this->coordinator(), $shift, ['headcount' => 3]);
    }

    public function test_editing_one_shift_sets_edited_at_and_is_logged(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->orderBy('local_date')->first();

        app(UpdateShift::class)->handle($this->coordinator(), $shift, ['start_time' => '17:00', 'end_time' => '20:00', 'headcount' => 2, 'shadow_spots' => 1, 'title' => 'Open house']);

        $shift->refresh();
        $this->assertSame('17:00', $this->localStart($shift));
        $this->assertSame('2026-10-16 00:00', $shift->ends_at->format('Y-m-d H:i')); // 8pm in Detroit is midnight UTC.
        $this->assertSame([2, 1, 'Open house'], [$shift->headcount, $shift->shadow_spots, $shift->title]);
        $this->assertNotNull($shift->edited_at);
        $entry = ChangeLogEntry::query()->ofAction('shift.updated')->sole();
        $this->assertSame([1, 2], [$entry->changes['headcount']['old'], $entry->changes['headcount']['new']]);
    }

    public function test_editing_one_shift_is_checked_against_its_area_and_refuses_started_shifts(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->orderBy('local_date')->first();

        try {
            app(UpdateShift::class)->handle($this->coordinator(Role::AreaLead, $this->hostedEvents), $shift, ['headcount' => 2]);
            $this->fail('A lead of another area edited the shift.');
        } catch (AuthorizationException) {
        }

        app(UpdateShift::class)->handle($this->coordinator(Role::AreaLead, $this->frontDesk), $shift, ['headcount' => 2]);
        $this->assertSame(2, $shift->fresh()->headcount);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', self::TIMEZONE));
        $this->expectException(DomainException::class);
        app(UpdateShift::class)->handle($this->coordinator(), $shift->fresh(), ['headcount' => 3]);
    }

    public function test_editing_a_slot_is_checked_against_its_area(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');

        $this->expectException(AuthorizationException::class);
        app(UpdateWeeklySlot::class)->handle($this->coordinator(Role::AreaLead, $this->hostedEvents), $slot, ['headcount' => 2] + $this->slotAttributes($slot));
    }

    public function test_a_hand_edited_shift_is_kept_when_the_slot_changes(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $edited = $slot->shifts()->orderBy('local_date')->skip(1)->first();
        app(UpdateShift::class)->handle($this->coordinator(), $edited, ['title' => 'Open house']);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['start_time' => '18:00', 'end_time' => '21:00'] + $this->slotAttributes($slot));

        $this->assertTrue($result->keptShifts->contains($edited));
        $this->assertSame(7, $result->updatedShifts);
        $this->assertSame('18:30', $this->localStart($edited->fresh()));
        $this->assertSame('Open house', $edited->fresh()->title);
    }

    public function test_changing_the_weekday_moves_unstaffed_shifts(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $staffed = $slot->shifts()->orderBy('local_date')->first();
        app(PutOnShift::class)->handle($this->coordinator(), $staffed, User::factory()->create(), AssignmentType::Regular);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['weekday' => 5, 'valid_from' => '2026-10-16'] + $this->slotAttributes($slot));

        $thursdays = $slot->shifts()->whereKeyNot($staffed->id)->get()->filter(fn (Shift $shift) => $shift->local_date->dayOfWeek === 4);
        $this->assertCount(7, $thursdays);
        $this->assertTrue($thursdays->every(fn (Shift $shift) => $shift->cancelled_at !== null && $shift->cancel_reason === 'The weekly slot changed'));
        $this->assertNull($staffed->fresh()->cancelled_at);
        $this->assertTrue($result->keptShifts->contains($staffed));
        $this->assertSame(8, $slot->shifts()->active()->get()->filter(fn (Shift $shift) => $shift->local_date->dayOfWeek === 5)->count());
        $this->assertSame(7, $result->cancelledShifts);
        $this->assertSame(8, $result->createdShifts);
        $this->assertSame(0, $result->updatedShifts);
        $entry = ChangeLogEntry::query()->ofAction('slot.updated')->sole();
        $this->assertCount(7, $entry->details['cancelled_shift_ids']);
        $this->assertSame(8, $entry->details['created_shifts']);
        Notification::assertNothingSentTo($staffed->assignments()->first()->user, ShiftCancelledNotification::class);
    }

    public function test_changing_to_every_other_week_cancels_the_weeks_in_between(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['frequency' => 'every_other_week'] + $this->slotAttributes($slot));

        $active = $slot->shifts()->active()->orderBy('local_date')->pluck('local_date')->map->toDateString()->all();
        $this->assertSame(['2026-10-15', '2026-10-29', '2026-11-12', '2026-11-26'], $active);
        $this->assertSame(4, $result->cancelledShifts);
        $this->assertSame(0, $result->updatedShifts); // The dates that stay already have the slot's details.
        $this->assertSame(0, $result->createdShifts);
    }

    public function test_changing_back_to_every_week_leaves_dates_the_slot_change_cancelled(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['frequency' => 'every_other_week'] + $this->slotAttributes($slot));

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['frequency' => 'weekly'] + $this->slotAttributes($slot->fresh()));

        // Cancelled shifts are never touched, and the unique date per slot means they can't be made again.
        $this->assertSame(0, $result->createdShifts);
        $this->assertSame(4, $result->stillCancelled);
        $this->assertSame(4, $slot->shifts()->active()->count());
        $this->assertSame(8, $slot->shifts()->count());
    }

    public function test_a_changed_first_date_has_to_be_from_today_on(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');

        $this->expectException(ValidationException::class);
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['valid_from' => '2026-10-08'] + $this->slotAttributes($slot));
    }

    public function test_an_unchanged_first_date_in_the_past_is_fine(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $this->travelTo(CarbonImmutable::parse('2026-10-30 09:00', self::TIMEZONE));

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['headcount' => 2] + $this->slotAttributes($slot));

        $this->assertSame('2026-10-15', $slot->fresh()->valid_from->toDateString());
        $this->assertSame(2, $slot->fresh()->headcount);
        $this->assertGreaterThan(0, $result->updatedShifts);
    }

    public function test_the_slot_form_saves_and_lists_the_shifts_people_are_on(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shifts = $slot->shifts()->orderBy('local_date')->get();
        $user = $this->coordinator();
        app(PutOnShift::class)->handle($user, $shifts[0], User::factory()->create(), AssignmentType::Regular);
        app(PutOnShift::class)->handle($user, $shifts[2], User::factory()->create(), AssignmentType::Regular);

        $this->actingAs($user)->get(route('calendar.slots.edit', $slot))
            ->assertOk()
            ->assertSee('Save the slot')
            ->assertSee('Changes apply to shifts nobody is on yet')
            ->assertSee('8 shifts from this slot are on the calendar, through Thursday, December 3.')
            ->assertSee('Saving changes the 6 that nobody is on yet. The 2 with people on them keep their old details.')
            ->assertDontSee('When editing a slot that already has shifts')
            ->assertDontSee("isn't available yet");

        $this->actingAs($user)->followingRedirects()
            ->post(route('calendar.slots.update', $slot), [
                'weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'headcount' => 1, 'shadow_spots' => 0,
                'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-15', 'valid_until' => '',
            ])
            ->assertOk()
            ->assertSee('Updated 6 shifts. 2 shifts with people on them kept their old details:')
            ->assertSee(route('calendar.shift', $shifts[0]), false)
            ->assertSee(route('calendar.shift', $shifts[2]), false)
            ->assertSee('Thursday, October 15, 6:30 to 9:30pm');

        $this->assertSame('18:00', $this->localStart($shifts[1]->fresh()));
    }

    public function test_the_slot_form_save_is_checked_against_the_area(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');

        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))
            ->post(route('calendar.slots.update', $slot), ['weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'headcount' => 1, 'shadow_spots' => 0, 'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-15', 'valid_until' => ''])
            ->assertForbidden();
    }

    public function test_the_shift_panel_offers_edit_for_this_shift_or_the_weekly_slot(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->orderBy('local_date')->first();
        $user = $this->coordinator();

        $this->actingAs($user)->get(route('calendar.shift', $shift))->assertOk()->assertSee(route('calendar.shift', [$shift, 'edit' => 1]), false);
        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))->get(route('calendar.shift', $shift))->assertOk()->assertDontSee(route('calendar.shift', [$shift, 'edit' => 1]), false);

        $this->actingAs($user)->get(route('calendar.shift', [$shift, 'edit' => 1]))
            ->assertOk()
            ->assertSee('Edit which shifts?')
            ->assertSee('This shift')
            ->assertSee('Only Thursday, October 15. Later changes to the weekly slot won&#039;t overwrite it.', false)
            ->assertSee('The weekly slot (shifts nobody is on yet)')
            ->assertDontSee('This and following shifts')
            ->assertDontSee('After pressing Edit');

        $this->actingAs($user)->get(route('calendar.shift', [$shift, 'edit' => 'slot']))->assertRedirect(route('calendar.slots.edit', $slot));
        $this->actingAs($user)->get(route('calendar.shift', [$shift, 'edit' => 'shift']))->assertOk()->assertSee('Save this shift');
    }

    public function test_the_shift_edit_form_saves_one_shift(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->orderBy('local_date')->first();

        $this->actingAs($this->coordinator())->followingRedirects()
            ->post(route('calendar.shift.update', $shift), ['start_time' => '17:00', 'end_time' => '20:00', 'headcount' => 2, 'shadow_spots' => 0, 'title' => ''])
            ->assertOk()
            ->assertSee('Saved the changes to this shift.');

        $this->assertSame('17:00', $this->localStart($shift->fresh()));
        $this->assertNotNull($shift->fresh()->edited_at);
    }

    public function test_the_shift_edit_form_explains_why_a_shift_with_people_cannot_be_edited(): void
    {
        $shift = $this->shiftWithPerson();
        $user = $this->coordinator();

        $this->actingAs($user)->get(route('calendar.shift', [$shift, 'edit' => 'shift']))
            ->assertOk()
            ->assertSee('Someone is on this shift. Editing shifts with people on them comes soon; for now, cancel this date and add a one-time shift.')
            ->assertDontSee('Save this shift');

        $this->actingAs($user)->post(route('calendar.shift.update', $shift), ['start_time' => '17:00', 'end_time' => '20:00', 'headcount' => 2, 'shadow_spots' => 0])
            ->assertRedirect();
        $this->assertSame(1, $shift->fresh()->headcount);
    }
}
