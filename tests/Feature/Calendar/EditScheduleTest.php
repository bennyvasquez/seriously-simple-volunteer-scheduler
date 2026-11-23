<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\RemoveFromShift;
use App\Actions\Scheduling\UpdateShift;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\PutOnShiftNotification;
use App\Notifications\ShiftChangedNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        // The emails are deferred until after the response; calling an action directly has no response, so run them right away.
        $this->withoutDefer();

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
            'weekday' => 4, 'start_time' => $start, 'end_time' => $end, 'people_needed' => 1, 'shadow_spots' => 0,
            'frequency' => $frequency, 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
    }

    /** The slot's current values, in the keys UpdateWeeklySlot takes. */
    private function slotAttributes(WeeklySlot $slot): array
    {
        return [
            'weekday' => $slot->weekday, 'start_time' => substr($slot->start_time, 0, 5), 'end_time' => substr($slot->end_time, 0, 5),
            'people_needed' => $slot->people_needed, 'shadow_spots' => $slot->shadow_spots, 'frequency' => $slot->frequency->value,
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

    public function test_editing_a_slot_updates_its_future_shifts_including_ones_people_are_on(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $staffed = $slot->shifts()->orderBy('local_date')->first();
        app(PutOnShift::class)->handle($this->coordinator(), $staffed, User::factory()->create(), AssignmentType::Regular);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['start_time' => '18:00', 'end_time' => '21:00'] + $this->slotAttributes($slot));

        $this->assertTrue($result->keptShifts->isEmpty());
        $this->assertSame([], $result->keptReasons);
        $this->assertSame('18:00', $this->localStart($staffed->fresh()));
        $this->assertNull($staffed->fresh()->edited_at);
        $this->assertSame(8, $result->updatedShifts);
        $this->assertSame(1, $result->emailed);

        $entry = ChangeLogEntry::query()->ofAction('slot.updated')->sole();
        $this->assertSame(8, $entry->details['updated_shifts']);
        $this->assertSame([], $entry->details['kept_shift_ids']);
        $this->assertSame(['18:30:00', '18:00:00'], [$entry->changes['start_time']['old'], $entry->changes['start_time']['new']]);
        $shiftEntry = ChangeLogEntry::query()->forSubject($staffed)->ofAction('shift.updated')->sole();
        $this->assertSame(['via' => 'slot.updated', 'weekly_slot_id' => $slot->id], $shiftEntry->details);
        $this->assertSame(
            [CarbonImmutable::parse('2026-10-15 18:30', self::TIMEZONE)->utc()->format('Y-m-d H:i:s'), CarbonImmutable::parse('2026-10-15 18:00', self::TIMEZONE)->utc()->format('Y-m-d H:i:s')],
            [$shiftEntry->changes['starts_at']['old'], $shiftEntry->changes['starts_at']['new']],
        );
    }

    /** Robin Example, a regular on the Thursday slot for its first 3 weeks (October 15, 22 and 29), with an example.org address. */
    private function robinOnThreeWeeks(WeeklySlot $slot): User
    {
        $robin = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
        app(AddRegular::class)->handle(null, $slot, $robin, '2026-10-15', '2026-10-29');

        return $robin;
    }

    public function test_a_slot_time_change_updates_staffed_shifts_and_emails_each_person_once(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        $robin = $this->robinOnThreeWeeks($slot);
        $robinsShifts = $slot->shifts()->whereHas('assignments', fn ($query) => $query->where('user_id', $robin->id))->orderBy('local_date')->get();
        $this->assertCount(3, $robinsShifts);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['start_time' => '17:00', 'end_time' => '20:00'] + $this->slotAttributes($slot));

        foreach ($robinsShifts as $shift) {
            $this->assertSame('17:00', $this->localStart($shift->fresh()));
            $this->assertSame([$robin->id], $shift->assignments()->pluck('user_id')->all());
            $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.updated')->sole();
            $this->assertSame(['via' => 'slot.updated', 'weekly_slot_id' => $slot->id], $entry->details);
            $this->assertArrayHasKey('ends_at', $entry->changes);
        }
        $this->assertSame(8, $result->updatedShifts);
        $this->assertSame([], $result->keptReasons);
        $this->assertSame(1, $result->emailed);

        Notification::assertSentTo($robin, ShiftChangedNotification::class, function (ShiftChangedNotification $notification) use ($robin) {
            $mail = $notification->toMail($robin);

            return $mail->subject === 'Your shifts changed'
                && $notification->lines === [
                    "Your Front desk shift on Thursday, October 15 changed. It's now 5 to 8pm; it was 6 to 9pm.",
                    "Your Front desk shift on Thursday, October 22 changed. It's now 5 to 8pm; it was 6 to 9pm.",
                    "Your Front desk shift on Thursday, October 29 changed. It's now 5 to 8pm; it was 6 to 9pm.",
                ];
        });
        Notification::assertSentTimes(ShiftChangedNotification::class, 1);
    }

    public function test_staffed_shifts_that_fail_a_check_are_kept_with_the_reason(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        $robin = $this->robinOnThreeWeeks($slot);
        $coordinator = $this->coordinator();
        $event = app(CreateOneTimeShift::class)->handle($coordinator, $this->hostedEvents, '2026-10-22', '17:00', '18:00', 1, 0, null);
        app(PutOnShift::class)->handle($coordinator, $event, $robin, AssignmentType::Regular);
        $shifts = $slot->shifts()->orderBy('local_date')->get()->keyBy(fn (Shift $shift) => $shift->local_date->toDateString());

        $result = app(UpdateWeeklySlot::class)->handle($coordinator, $slot, ['start_time' => '17:00', 'end_time' => '20:00'] + $this->slotAttributes($slot));

        $kept = $shifts['2026-10-22'];
        $this->assertSame('18:00', $this->localStart($kept->fresh()));
        $this->assertSame([$kept->id => 'Robin Example is on Hosted events, Thursday, October 22, 5 to 6pm at that time.'], $result->keptReasons);
        $this->assertTrue($result->keptShifts->contains($kept));
        $this->assertSame('17:00', $this->localStart($shifts['2026-10-15']->fresh()));
        $this->assertSame('17:00', $this->localStart($shifts['2026-10-29']->fresh()));
        $this->assertSame(7, $result->updatedShifts);
        $this->assertFalse(ChangeLogEntry::query()->forSubject($kept)->ofAction('shift.updated')->exists());
        $entry = ChangeLogEntry::query()->ofAction('slot.updated')->sole();
        $this->assertSame([$kept->id], $entry->details['kept_shift_ids']);
        $this->assertStringNotContainsString('Robin', json_encode($entry->details));

        Notification::assertSentTo($robin, ShiftChangedNotification::class, fn (ShiftChangedNotification $notification) => $notification->lines === [
            "Your Front desk shift on Thursday, October 15 changed. It's now 5 to 8pm; it was 6 to 9pm.",
            "Your Front desk shift on Thursday, October 29 changed. It's now 5 to 8pm; it was 6 to 9pm.",
        ]);
        Notification::assertSentTimes(ShiftChangedNotification::class, 1);
    }

    public function test_hand_edited_shifts_are_still_kept(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        $robin = $this->robinOnThreeWeeks($slot);
        $edited = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        // Changing only the people needed emails nobody, so the only email below is the slot change's.
        app(UpdateShift::class)->handle($this->coordinator(), $edited, ['people_needed' => 2]);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['start_time' => '17:00', 'end_time' => '20:00'] + $this->slotAttributes($slot));

        $this->assertSame('18:00', $this->localStart($edited->fresh()));
        $this->assertSame([$edited->id => 'Changed on its own'], $result->keptReasons);
        $this->assertSame(7, $result->updatedShifts);
        Notification::assertSentTo($robin, ShiftChangedNotification::class, fn (ShiftChangedNotification $notification) => count($notification->lines) === 2
            && ! str_contains(implode(' ', $notification->lines), 'October 22'));
        Notification::assertSentTimes(ShiftChangedNotification::class, 1);
    }

    public function test_a_staffed_shift_the_slot_no_longer_runs_on_is_kept_with_the_reason(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        $this->robinOnThreeWeeks($slot);
        $last = $slot->shifts()->where('local_date', '2026-10-29')->sole();

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['valid_until' => '2026-10-22'] + $this->slotAttributes($slot));

        $this->assertNull($last->fresh()->cancelled_at);
        $this->assertSame([$last->id => "The slot doesn't run on this date any more"], $result->keptReasons);
        $this->assertSame(0, $result->emailed);
        Notification::assertNotSentTo(User::query()->where('email', 'robin@example.org')->sole(), ShiftChangedNotification::class);
    }

    public function test_changing_only_people_needed_or_shadow_spots_emails_nobody_and_only_people_who_can_sign_in_are_emailed(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        $robin = $this->robinOnThreeWeeks($slot);
        $casey = User::factory()->notInvited()->create(['name' => 'Casey Example', 'email' => 'casey@example.org']);
        app(AddRegular::class)->handle(null, $slot, $casey, '2026-10-15', '2026-10-29', SlotFrequency::Weekly, null, true);
        $this->assertSame(3, Assignment::query()->where('user_id', $casey->id)->count());

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['people_needed' => 3, 'shadow_spots' => 1] + $this->slotAttributes($slot));

        $this->assertSame(8, $result->updatedShifts);
        $this->assertSame(0, $result->emailed);
        $this->assertSame(3, $slot->shifts()->where('local_date', '2026-10-15')->sole()->people_needed);
        Notification::assertSentTimes(ShiftChangedNotification::class, 0);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['title' => 'Greeter'] + $this->slotAttributes($slot->fresh()));

        $this->assertSame(1, $result->emailed);
        Notification::assertSentTo($robin, ShiftChangedNotification::class, fn (ShiftChangedNotification $notification) => count($notification->lines) === 3
            && $notification->lines[0] === "Your Front desk shift on Thursday, October 15 changed. It's now for Greeter.");
        Notification::assertNotSentTo($casey, ShiftChangedNotification::class);
    }

    public function test_who_is_on_each_shift_is_read_under_the_lock(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        $casey = User::factory()->create(['name' => 'Casey Example', 'email' => 'casey@example.org']);
        $shift = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        $coordinator = $this->coordinator();

        // Record the save's locking reads, and put Casey on a shift right after the save locks its shifts, the way a PutOnShift could commit while the save waited for those locks.
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks, $casey, $shift) {
            if (! str_contains($query->sql, 'for update') && ! str_contains($query->sql, 'lock in share mode')) {
                return;
            }

            preg_match('/from `(\w+)`/', $query->sql, $table);
            $locks[] = $table[1];

            if ($table[1] === 'shifts' && ! in_array('put on', $locks, true)) {
                $locks[] = 'put on';
                DB::table('assignments')->insert(['shift_id' => $shift->id, 'user_id' => $casey->id, 'type' => AssignmentType::Regular->value, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $result = app(UpdateWeeklySlot::class)->handle($coordinator, $slot, ['start_time' => '17:00', 'end_time' => '20:00'] + $this->slotAttributes($slot));

        // Area, slot, regulars, shifts, then the people on those shifts.
        $this->assertSame(['areas', 'weekly_slots', 'slot_regulars', 'shifts', 'put on', 'assignments'], array_slice($locks, 0, 6));
        $this->assertSame('17:00', $this->localStart($shift->fresh()));
        $this->assertSame(1, $result->emailed);
        Notification::assertSentTo($casey, ShiftChangedNotification::class, fn (ShiftChangedNotification $notification) => $notification->lines === [
            "Your Front desk shift on Thursday, October 22 changed. It's now 5 to 8pm; it was 6 to 9pm.",
        ]);
    }

    public function test_a_shift_someone_was_just_put_on_is_not_cancelled_when_the_slot_stops_running_on_it(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        $casey = User::factory()->create(['name' => 'Casey Example', 'email' => 'casey@example.org']);
        $last = $slot->shifts()->where('local_date', '2026-12-03')->sole();
        $inserted = false;
        DB::listen(function (QueryExecuted $query) use (&$inserted, $casey, $last) {
            if (! $inserted && str_contains($query->sql, 'for update') && preg_match('/^select \* from `shifts`/', $query->sql)) {
                $inserted = true;
                DB::table('assignments')->insert(['shift_id' => $last->id, 'user_id' => $casey->id, 'type' => AssignmentType::Regular->value, 'created_at' => now(), 'updated_at' => now()]);
            }
        });

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['valid_until' => '2026-11-26'] + $this->slotAttributes($slot));

        $this->assertNull($last->fresh()->cancelled_at);
        $this->assertSame([$last->id => "The slot doesn't run on this date any more"], $result->keptReasons);
    }

    public function test_lowering_shadow_spots_below_the_shadows_on_a_shift_keeps_it(): void
    {
        $slot = $this->thursdaySlot('18:00', '21:00');
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['shadow_spots' => 1] + $this->slotAttributes($slot));
        $shift = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        app(PutOnShift::class)->handle($this->coordinator(), $shift, User::factory()->create(), AssignmentType::Shadow);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['shadow_spots' => 0] + $this->slotAttributes($slot->fresh()));

        $this->assertSame([$shift->id => '1 person is shadowing this shift. Remove someone from it before lowering it to 0.'], $result->keptReasons);
        $this->assertSame(1, $shift->fresh()->shadow_spots);
        $this->assertSame(7, $result->updatedShifts);
    }

    public function test_editing_a_slot_never_touches_started_or_cancelled_shifts(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shifts = $slot->shifts()->orderBy('local_date')->get();
        app(CancelShift::class)->handle($this->coordinator(), $shifts[1], 'Power outage');
        // A week later, the first shift has started.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', self::TIMEZONE));

        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['people_needed' => 3, 'title' => 'Greeter'] + $this->slotAttributes($slot));

        $this->assertSame(1, $shifts[0]->fresh()->people_needed);
        $this->assertSame(1, $shifts[1]->fresh()->people_needed);
        $this->assertSame('Power outage', $shifts[1]->fresh()->cancel_reason);
        $this->assertSame(3, $shifts[2]->fresh()->people_needed);
        $this->assertSame('Greeter', $shifts[2]->fresh()->title);
    }

    public function test_editing_one_shift_sets_edited_at_and_is_logged(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->orderBy('local_date')->first();

        app(UpdateShift::class)->handle($this->coordinator(), $shift, ['start_time' => '17:00', 'end_time' => '20:00', 'people_needed' => 2, 'shadow_spots' => 1, 'title' => 'Open house']);

        $shift->refresh();
        $this->assertSame('17:00', $this->localStart($shift));
        $this->assertSame('2026-10-16 00:00', $shift->ends_at->format('Y-m-d H:i')); // 8pm in Detroit is midnight UTC.
        $this->assertSame([2, 1, 'Open house'], [$shift->people_needed, $shift->shadow_spots, $shift->title]);
        $this->assertNotNull($shift->edited_at);
        $entry = ChangeLogEntry::query()->ofAction('shift.updated')->sole();
        $this->assertSame([1, 2], [$entry->changes['people_needed']['old'], $entry->changes['people_needed']['new']]);
    }

    public function test_editing_one_shift_is_checked_against_its_area_and_refuses_started_shifts(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->orderBy('local_date')->first();

        try {
            app(UpdateShift::class)->handle($this->coordinator(Role::AreaLead, $this->hostedEvents), $shift, ['people_needed' => 2]);
            $this->fail('A lead of another area edited the shift.');
        } catch (AuthorizationException) {
        }

        app(UpdateShift::class)->handle($this->coordinator(Role::AreaLead, $this->frontDesk), $shift, ['people_needed' => 2]);
        $this->assertSame(2, $shift->fresh()->people_needed);

        $this->travelTo(CarbonImmutable::parse('2026-10-15 19:00', self::TIMEZONE));
        $this->expectException(DomainException::class);
        app(UpdateShift::class)->handle($this->coordinator(), $shift->fresh(), ['people_needed' => 3]);
    }

    public function test_editing_a_slot_is_checked_against_its_area(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');

        $this->expectException(AuthorizationException::class);
        app(UpdateWeeklySlot::class)->handle($this->coordinator(Role::AreaLead, $this->hostedEvents), $slot, ['people_needed' => 2] + $this->slotAttributes($slot));
    }

    public function test_a_hand_edited_shift_is_kept_when_the_slot_changes(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $edited = $slot->shifts()->orderBy('local_date')->skip(1)->first();
        app(UpdateShift::class)->handle($this->coordinator(), $edited, ['title' => 'Open house']);

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['start_time' => '18:00', 'end_time' => '21:00'] + $this->slotAttributes($slot));

        $this->assertTrue($result->keptShifts->contains($edited));
        $this->assertSame([$edited->id => 'Changed on its own'], $result->keptReasons);
        $this->assertSame(7, $result->updatedShifts);
        $this->assertSame('18:30', $this->localStart($edited->fresh()));
        $this->assertSame('Open house', $edited->fresh()->title);
    }

    public function test_changing_the_weekday_moves_unstaffed_shifts(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $thursdays = $slot->shifts()->orderBy('local_date')->get();

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['weekday' => 5] + $this->slotAttributes($slot));

        $this->assertTrue($thursdays->every(fn (Shift $shift) => $shift->fresh()->cancelled_at !== null && $shift->fresh()->cancel_reason === 'The weekly slot changed'));
        $this->assertSame(8, $slot->shifts()->active()->get()->filter(fn (Shift $shift) => $shift->local_date->dayOfWeek === 5)->count());
        $this->assertSame([8, 8, 0], [$result->cancelledShifts, $result->createdShifts, $result->updatedShifts]);
        $entry = ChangeLogEntry::query()->ofAction('slot.updated')->sole();
        $this->assertCount(8, $entry->details['cancelled_shift_ids']);
        $this->assertSame(8, $entry->details['created_shifts']);
        Notification::assertNothingSent();
    }

    public function test_every_shift_a_slot_edit_changes_gets_its_own_change_log_entry(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $first = $slot->shifts()->orderBy('local_date')->first();

        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['start_time' => '18:00', 'end_time' => '21:00'] + $this->slotAttributes($slot));
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['weekday' => 5] + $this->slotAttributes($slot->fresh()));

        $retimed = ChangeLogEntry::query()->forSubject($first)->ofAction('shift.updated')->sole();
        $this->assertSame(['via' => 'slot.updated', 'weekly_slot_id' => $slot->id], $retimed->details);
        $this->assertArrayHasKey('starts_at', $retimed->changes);
        $cancelled = ChangeLogEntry::query()->forSubject($first)->ofAction('shift.cancelled')->sole();
        $this->assertSame(['via' => 'slot.updated', 'weekly_slot_id' => $slot->id], $cancelled->details);
        $this->assertSame('The weekly slot changed', $cancelled->changes['cancel_reason']['new']);
        $this->assertSame(16, ChangeLogEntry::query()->ofAction('shift.updated', 'shift.cancelled')->count());
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

    public function test_changing_back_to_every_week_brings_back_the_dates_the_slot_change_cancelled(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $outage = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        app(CancelShift::class)->handle($this->coordinator(), $outage, 'Power outage');
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['frequency' => 'every_other_week'] + $this->slotAttributes($slot));

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['frequency' => 'weekly', 'people_needed' => 2] + $this->slotAttributes($slot->fresh()));

        // Nov 5, Nov 19 and Dec 3 come back with the slot's new details; Oct 22 was cancelled by a person and stays cancelled.
        $this->assertSame(0, $result->createdShifts);
        $this->assertSame(7, $result->updatedShifts);
        $this->assertSame(7, $slot->shifts()->active()->count());
        $this->assertSame(8, $slot->shifts()->count());
        $this->assertSame('Power outage', $outage->fresh()->cancel_reason);
        $revived = $slot->shifts()->where('local_date', '2026-11-05')->sole();
        $this->assertNull($revived->cancel_reason);
        $this->assertSame(2, $revived->people_needed);
        $this->assertTrue(ChangeLogEntry::query()->forSubject($revived)->ofAction('shift.updated')->exists());
    }

    public function test_dates_brought_back_get_the_slots_current_regulars(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['valid_until' => '2026-11-12'] + $this->slotAttributes($slot));
        $sam = $this->regular($slot->fresh(), 'Sam Example');

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['valid_until' => null] + $this->slotAttributes($slot->fresh()));

        $this->assertSame(3, $result->updatedShifts);
        foreach (['2026-11-19', '2026-11-26', '2026-12-03'] as $date) {
            $shift = $slot->shifts()->where('local_date', $date)->sole();
            $this->assertNull($shift->cancelled_at);
            $this->assertSame([$sam->id], $shift->assignments()->where('type', AssignmentType::Regular)->pluck('user_id')->all());
            $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.updated')->sole();
            $this->assertSame([$sam->id], $entry->details['added_regular_ids']);
        }
        Notification::assertNotSentTo($sam, PutOnShiftNotification::class);
    }

    public function test_dates_brought_back_leave_out_regulars_who_have_been_turned_off(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['valid_until' => '2026-11-12'] + $this->slotAttributes($slot));
        $sam = $this->regular($slot->fresh(), 'Sam Example');
        $sam->forceFill(['deactivated_at' => now()])->save();

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['valid_until' => null] + $this->slotAttributes($slot->fresh()));

        $this->assertSame(3, $result->updatedShifts);
        foreach (['2026-11-19', '2026-11-26', '2026-12-03'] as $date) {
            $shift = $slot->shifts()->where('local_date', $date)->sole();
            $this->assertNull($shift->cancelled_at);
            $this->assertSame(0, $shift->assignments()->count());
            $this->assertSame([], ChangeLogEntry::query()->forSubject($shift)->ofAction('shift.updated')->sole()->details['added_regular_ids']);
        }
    }

    public function test_dates_brought_back_leave_out_a_regular_who_was_taken_off_that_date(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $sam = $this->regular($slot, 'Sam Example');
        $nov19 = $slot->shifts()->where('local_date', '2026-11-19')->sole();
        app(RemoveFromShift::class)->one($this->coordinator(), $nov19, $nov19->assignments()->sole());
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['valid_until' => '2026-11-12'] + $this->slotAttributes($slot->fresh()));
        $this->assertNotNull($nov19->fresh()->cancelled_at);

        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot->fresh(), ['valid_until' => null] + $this->slotAttributes($slot->fresh()));

        $this->assertNull($nov19->fresh()->cancelled_at);
        $this->assertSame(0, $nov19->assignments()->count());
        $this->assertSame([], ChangeLogEntry::query()->forSubject($nov19)->ofAction('shift.updated')->latest('id')->first()->details['added_regular_ids']);
    }

    public function test_bringing_dates_back_reads_regulars_and_people_under_the_lock(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['valid_until' => '2026-11-12'] + $this->slotAttributes($slot));
        $sam = $this->regular($slot->fresh(), 'Sam Example');
        $coordinator = $this->coordinator();
        $stale = $slot->fresh(['regulars.user']);

        // Record the save's locking reads, and turn Sam off right after it locks the cancelled shifts it might bring back, the way a turn-off could commit while the save runs.
        $locks = [];
        DB::listen(function (QueryExecuted $query) use (&$locks, $sam) {
            if (! str_contains($query->sql, 'for update') && ! str_contains($query->sql, 'lock in share mode')) {
                return;
            }

            // The table after removing subqueries (a count or a whereHas), so it names the rows being locked.
            $outer = $query->sql;
            while (preg_match('/\([^()]*\)/', $outer)) {
                $outer = preg_replace('/\([^()]*\)/', '', $outer);
            }
            preg_match('/from `(\w+)`/', $outer, $table);
            $locks[] = $table[1].(str_contains($query->sql, 'lock in share mode') ? ' shared' : '');

            if (str_contains($query->sql, '`cancelled_at` is not null') && ! in_array('turned off', $locks, true)) {
                $locks[] = 'turned off';
                DB::table('users')->where('id', $sam->id)->update(['deactivated_at' => now()]);
            }
        });

        app(UpdateWeeklySlot::class)->handle($coordinator, $stale, ['valid_until' => null] + $this->slotAttributes($slot->fresh()));

        // Area, slot, then the slot's regulars, all before the first shift lock, and each person is read again with a shared lock.
        $this->assertSame(['areas', 'weekly_slots', 'slot_regulars', 'shifts'], array_slice($locks, 0, 4));
        $this->assertContains('users shared', array_slice($locks, array_search('turned off', $locks, true)));

        foreach (['2026-11-19', '2026-11-26', '2026-12-03'] as $date) {
            $shift = $slot->shifts()->where('local_date', $date)->sole();
            $this->assertNull($shift->cancelled_at);
            $this->assertSame(0, $shift->assignments()->count());
        }
    }

    public function test_a_cancellation_typed_by_hand_with_the_same_words_is_never_undone(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        app(CancelShift::class)->handle($this->coordinator(), $shift, 'The weekly slot changed');

        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['people_needed' => 2] + $this->slotAttributes($slot));

        $this->assertNotNull($shift->fresh()->cancelled_at);
        $this->assertSame(1, $shift->fresh()->people_needed);
    }

    /** Runs a slot edit that has to be refused with $message, and checks nothing changed. */
    private function assertRefused(WeeklySlot $slot, array $changes, string $message): void
    {
        $slotBefore = $slot->fresh()->getAttributes();
        $shiftsBefore = Shift::query()->orderBy('id')->get()->map->getAttributes()->all();
        $entriesBefore = ChangeLogEntry::query()->count();

        try {
            app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, $changes + $this->slotAttributes($slot));
            $this->fail('The slot edit was allowed.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertEquals($slotBefore, $slot->fresh()->getAttributes());
        $this->assertEquals($shiftsBefore, Shift::query()->orderBy('id')->get()->map->getAttributes()->all());
        // Only the coordinator made for the call was logged: their role, and the clearance holding it brings.
        $this->assertSame($entriesBefore + 2, ChangeLogEntry::query()->count());
        $this->assertFalse(ChangeLogEntry::query()->ofAction('slot.updated')->exists());
    }

    private function regular(WeeklySlot $slot, string $name, ?string $alternateFrom = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(AddRegular::class)->handle(null, $slot, $user, '2026-10-15', null, $alternateFrom === null ? SlotFrequency::Weekly : SlotFrequency::EveryOtherWeek, $alternateFrom);

        return $user;
    }

    public function test_how_often_a_slot_repeats_cannot_change_while_a_regular_works_it(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $this->regular($slot, 'Sam Example');

        $this->assertRefused($slot, ['frequency' => 'every_other_week'], 'Sam Example works this slot. Changing its day or how often it repeats changes when they work, and that needs the change notices coming in the next update. For now, set a last date on this slot and add a new one.');
    }

    public function test_the_day_cannot_move_earlier_while_alternating_regulars_work_it(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $this->regular($slot, 'Sam Example', '2026-10-15');
        $this->regular($slot, 'Robin Example', '2026-10-22');

        $this->assertRefused($slot, ['weekday' => 2], 'Sam Example and Robin Example work this slot. Changing its day or how often it repeats changes when they work, and that needs the change notices coming in the next update. For now, set a last date on this slot and add a new one.');
    }

    public function test_the_day_cannot_change_while_someone_is_on_a_future_shift(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shift = $slot->shifts()->orderBy('local_date')->skip(3)->first();
        app(PutOnShift::class)->handle($this->coordinator(), $shift, User::factory()->create(['name' => 'Alex Example']), AssignmentType::Regular);

        $this->assertRefused($slot, ['weekday' => 5], 'Alex Example works this slot. Changing its day or how often it repeats changes when they work, and that needs the change notices coming in the next update. For now, set a last date on this slot and add a new one.');
    }

    public function test_a_slot_with_regulars_cannot_become_a_rotation(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $this->regular($slot, 'Sam Example');

        $this->assertRefused($slot, ['is_rotation' => true], 'Sam Example is a regular on this slot. Ask an admin to end their time as a regular before making it a rotation.');
    }

    public function test_the_rotation_refusal_names_every_regular(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $this->regular($slot, 'Sam Example', '2026-10-15');
        $this->regular($slot, 'Robin Example', '2026-10-22');

        $this->assertRefused($slot, ['is_rotation' => true], 'Sam Example and Robin Example are regulars on this slot. Ask an admin to end their time as regulars before making it a rotation.');
    }

    public function test_a_regular_whose_time_has_ended_does_not_block_a_change(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $sam = User::factory()->create();
        app(AddRegular::class)->handle(null, $slot, $sam, '2026-10-01', '2026-10-13');

        app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['weekday' => 5, 'is_rotation' => true] + $this->slotAttributes($slot));

        $this->assertSame(5, $slot->fresh()->weekday);
        $this->assertTrue($slot->fresh()->is_rotation);
    }

    public function test_the_slot_form_shows_why_a_change_is_refused(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $this->regular($slot, 'Sam Example');

        $this->actingAs($this->coordinator())->followingRedirects()
            ->post(route('calendar.slots.update', $slot), ['weekday' => 5, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0, 'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-15', 'valid_until' => ''])
            ->assertOk()
            ->assertSee('Sam Example works this slot. Changing its day or how often it repeats changes when they work')
            ->assertSee('<option value="5" selected', false);

        $this->assertSame(4, $slot->fresh()->weekday);
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

        $result = app(UpdateWeeklySlot::class)->handle($this->coordinator(), $slot, ['people_needed' => 2] + $this->slotAttributes($slot));

        $this->assertSame('2026-10-15', $slot->fresh()->valid_from->toDateString());
        $this->assertSame(2, $slot->fresh()->people_needed);
        $this->assertGreaterThan(0, $result->updatedShifts);
    }

    public function test_the_slot_form_saves_and_lists_the_kept_shifts_with_their_reasons(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shifts = $slot->shifts()->orderBy('local_date')->get();
        $user = $this->coordinator();
        app(PutOnShift::class)->handle($user, $shifts[0], User::factory()->create(), AssignmentType::Regular);
        app(PutOnShift::class)->handle($user, $shifts[2], User::factory()->create(), AssignmentType::Regular);
        app(UpdateShift::class)->handle($user, $shifts[4], ['people_needed' => 2]);

        $this->actingAs($user)->get(route('calendar.slots.edit', $slot))
            ->assertOk()
            ->assertSee('Save the slot')
            ->assertSee('Changes apply to future shifts nobody changed on their own')
            ->assertSee('8 shifts from this slot are on the calendar, through Thursday, December 3.')
            ->assertSee('Saving changes the 7 nobody changed on their own, including the 2 with people on them. The 1 edited on its own keeps its own details. Anyone on those shifts is emailed if the time or what it&#039;s for changes. A shift with people on it keeps its old details if the change would double-book someone or leave too few shadow spots.', false)
            ->assertDontSee('nobody is on yet')
            ->assertDontSee("isn't available yet");

        $this->actingAs($user)->followingRedirects()
            ->post(route('calendar.slots.update', $slot), [
                'weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'people_needed' => 1, 'shadow_spots' => 0,
                'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-15', 'valid_until' => '',
            ])
            ->assertOk()
            ->assertSee('Updated 7 shifts. 2 people were emailed about their changed shifts. 1 shift kept its old details:')
            ->assertSee(route('calendar.shift', $shifts[4]), false)
            ->assertDontSee(route('calendar.shift', $shifts[0]), false)
            ->assertSee('Thursday, November 12, 6:30 to 9:30pm')
            ->assertSee('Changed on its own.');

        $this->assertSame('18:00', $this->localStart($shifts[0]->fresh()));
        $this->assertSame('18:00', $this->localStart($shifts[1]->fresh()));
    }

    public function test_the_slot_form_says_1_person_was_emailed(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $user = $this->coordinator();
        app(PutOnShift::class)->handle($user, $slot->shifts()->orderBy('local_date')->first(), User::factory()->create(), AssignmentType::Regular);

        $this->actingAs($user)->followingRedirects()
            ->post(route('calendar.slots.update', $slot), [
                'weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'people_needed' => 1, 'shadow_spots' => 0,
                'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-15', 'valid_until' => '',
            ])
            ->assertOk()
            ->assertSee('Updated 8 shifts. 1 person was emailed about their changed shifts.');
    }

    public function test_an_earlier_last_date_lists_the_shifts_people_are_on_that_no_longer_fit(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');
        $shifts = $slot->shifts()->orderBy('local_date')->get()->keyBy(fn (Shift $shift) => $shift->local_date->toDateString());
        $user = $this->coordinator();
        foreach (['2026-10-22', '2026-10-29', '2026-11-26', '2026-12-03'] as $date) {
            app(PutOnShift::class)->handle($user, $shifts[$date], User::factory()->create(), AssignmentType::Regular);
        }

        $this->actingAs($user)->followingRedirects()
            ->post(route('calendar.slots.update', $slot), [
                'weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'people_needed' => 1, 'shadow_spots' => 0,
                'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-15', 'valid_until' => '2026-11-12',
            ])
            ->assertOk()
            ->assertSee('Updated 5 shifts. Cancelled 1 date that no longer fit the slot. Nobody was on it. 2 people were emailed about their changed shifts. 2 shifts kept their old details:')
            ->assertSee('The slot doesn&#039;t run on this date any more.', false)
            ->assertSee("If a date the slot doesn't run on any more isn't needed, cancel it from the calendar.", false)
            ->assertSee('Thursday, November 26, 6:30 to 9:30pm')
            ->assertSee(route('calendar.shift', $shifts['2026-12-03']), false);

        $this->assertSame('The weekly slot changed', $shifts['2026-11-19']->fresh()->cancel_reason);
        $this->assertNull($shifts['2026-11-26']->fresh()->cancelled_at);
        $this->assertSame('18:00', $this->localStart($shifts['2026-10-22']->fresh()));
    }

    public function test_the_slot_form_save_is_checked_against_the_area(): void
    {
        $slot = $this->thursdaySlot('18:30', '21:30');

        $this->actingAs($this->coordinator(Role::AreaLead, $this->hostedEvents))
            ->post(route('calendar.slots.update', $slot), ['weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'people_needed' => 1, 'shadow_spots' => 0, 'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-15', 'valid_until' => ''])
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
            ->assertSee("Only Thursday, October 15. Later changes to the weekly slot won't overwrite it.", false)
            ->assertSee('The weekly slot (future shifts nobody changed on their own)')
            ->assertSee("Shifts with people on them change too, unless that would double-book someone or leave too few shadow spots. The people on them are emailed if the time or what it's for changes.", false)
            ->assertDontSee('keep their old details')
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
            ->post(route('calendar.shift.update', $shift), ['start_time' => '17:00', 'end_time' => '20:00', 'people_needed' => 2, 'shadow_spots' => 0, 'title' => ''])
            ->assertOk()
            ->assertSee('Saved the changes to this shift.');

        $this->assertSame('17:00', $this->localStart($shift->fresh()));
        $this->assertNotNull($shift->fresh()->edited_at);
    }
}
