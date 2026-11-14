<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\ReleaseFutureSchedule;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\Enums\AssignmentType;
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
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ReleaseFutureScheduleTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    private User $robin;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 4]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->coordinator = User::factory()->create(['name' => 'Alex Coordinator', 'email' => 'alex@example.org']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
        $this->robin = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
    }

    private function shift(Area $area, string $localDate, bool $cancelled = false): Shift
    {
        return Shift::query()->create([
            'area_id' => $area->id, 'local_date' => $localDate,
            'starts_at' => CarbonImmutable::parse($localDate.' 18:00', self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse($localDate.' 21:00', self::TIMEZONE)->utc(),
            'people_needed' => 2, 'shadow_spots' => 1, 'cancelled_at' => $cancelled ? now() : null,
        ]);
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): Assignment
    {
        return Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
    }

    /** @return list<string> */
    private function datesFor(User $person): array
    {
        return Shift::query()
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id))
            ->orderBy('starts_at')->get()
            ->map(fn (Shift $shift) => $shift->local_date->toDateString())
            ->all();
    }

    public function test_turning_off_clears_the_future_and_generation_does_not_undo_it(): void
    {
        $thursdays = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 2]);
        app(GenerateShifts::class)->handle(Organization::current());
        $regular = app(AddRegular::class)->handle(null, $thursdays, $this->robin, '2026-10-15', null, SlotFrequency::EveryOtherWeek, '2026-10-15');
        $saturday = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->hostedEvents, '2026-10-17', '10:00', '14:00', 2, 0, null);
        app(PutOnShift::class)->handle($this->coordinator, $saturday, $this->robin, AssignmentType::Regular);
        $cancelledTuesday = $this->shift($this->frontDesk, '2026-10-20', cancelled: true);
        $this->on($cancelledTuesday, $this->robin);
        $past = $this->shift($this->frontDesk, '2026-10-07');
        $this->on($past, $this->robin);

        $this->assertSame(['2026-10-07', '2026-10-15', '2026-10-17', '2026-10-20', '2026-10-29'], $this->datesFor($this->robin));
        $futureIds = Shift::query()->where('starts_at', '>', now())->whereHas('assignments', fn ($query) => $query->where('user_id', $this->robin->id))->pluck('id')->sort()->values()->all();
        Notification::fake();

        $this->actingAs($this->coordinator)->post(route('people.turn-off', $this->robin))
            ->assertRedirect(route('people.show', $this->robin))
            ->assertSessionHas('status', 'Robin\'s account is turned off. They were removed from 4 future shifts.');

        $this->assertFalse($this->robin->fresh()->isActive());
        $this->assertSame(['2026-10-07'], $this->datesFor($this->robin));
        // The regular starts tomorrow, so it ends the day before its start, which is later than yesterday.
        $this->assertSame('2026-10-14', $regular->fresh()->ends_on->toDateString());
        Notification::assertNothingSentTo($this->robin);

        $ended = ChangeLogEntry::query()->about($this->robin)->ofAction('regular.ended')->sole();
        $this->assertSame($this->coordinator->id, $ended->actor_id);
        $this->assertSame($regular->id, $ended->subject_id);
        $this->assertSame(['ends_on' => ['old' => null, 'new' => '2026-10-14']], $ended->changes);
        $this->assertSame('user.deactivated', $ended->details['via']);
        $this->assertSame($thursdays->id, $ended->details['weekly_slot_id']);

        $removed = ChangeLogEntry::query()->about($this->robin)->ofAction('assignment.removed')->orderBy('subject_id')->get();
        $this->assertSame($futureIds, $removed->pluck('subject_id')->all());
        $this->assertTrue($removed->every(fn (ChangeLogEntry $entry) => $entry->details['via'] === 'user.deactivated' && $entry->actor_id === $this->coordinator->id));
        $this->assertTrue($removed->every(fn (ChangeLogEntry $entry) => $entry->changes['user_id'] === ['old' => $this->robin->id, 'new' => null]));

        // Generation over a longer window puts Robin on nothing new.
        Organization::current()->update(['weeks_ahead' => 8]);
        app(GenerateShifts::class)->handle(Organization::current());
        $this->assertGreaterThan(4, $thursdays->shifts()->count());
        $this->assertSame(['2026-10-07'], $this->datesFor($this->robin));
    }

    public function test_a_future_regular_ends_before_it_starts_and_never_counts_as_current(): void
    {
        $thursdays = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'valid_from' => '2026-10-01']);
        $regular = SlotRegular::create(['weekly_slot_id' => $thursdays->id, 'user_id' => $this->robin->id, 'starts_on' => '2026-11-05', 'frequency' => SlotFrequency::EveryOtherWeek, 'anchor_date' => '2026-11-05']);

        $this->assertSame(['shifts' => 0, 'regulars' => 1], ReleaseFutureSchedule::counts($this->robin));
        $this->assertSame(['shifts' => 0, 'regulars' => 1], app(DeactivateUser::class)->handle($this->coordinator, $this->robin));

        $this->assertSame('2026-11-04', $regular->fresh()->ends_on->toDateString());
        $this->assertSame(['shifts' => 0, 'regulars' => 0], ReleaseFutureSchedule::counts($this->robin));

        // Not shown as a current regular on the add-regular page, and not offered under "Which weeks".
        $this->actingAs($this->coordinator)->get(route('calendar.slots.regulars.create', $thursdays))
            ->assertOk()
            ->assertSee('No regulars yet.')
            ->assertDontSee('Robin')
            ->assertDontSee('The weeks');

        // It doesn't stop the slot's day from changing.
        app(UpdateWeeklySlot::class)->handle($this->coordinator, $thursdays->fresh(), [
            'weekday' => 5, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-01', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
        $this->assertSame(5, $thursdays->fresh()->weekday);
    }

    public function test_turning_off_an_already_turned_off_person_tidies_their_schedule(): void
    {
        // Turned off before turning off cleared the schedule.
        $this->robin->forceFill(['deactivated_at' => now()->subWeek()])->save();
        $future = $this->shift($this->frontDesk, '2026-10-21');
        $this->on($future, $this->robin);

        $this->actingAs($this->coordinator)->post(route('people.turn-off', $this->robin))
            ->assertSessionHas('status', 'Robin\'s account is turned off. They were removed from 1 future shift.');

        $this->assertSame([], $this->datesFor($this->robin));
        $this->assertSame(0, ChangeLogEntry::query()->about($this->robin)->ofAction('user.deactivated')->count());
        $this->assertSame('user.deactivated', ChangeLogEntry::query()->about($this->robin)->ofAction('assignment.removed')->sole()->details['via']);

        // Nothing left, so the second time changes nothing.
        $this->actingAs($this->coordinator)->post(route('people.turn-off', $this->robin))
            ->assertSessionHas('status', 'Nothing changed.');
    }

    public function test_a_shift_that_has_started_is_left_alone(): void
    {
        $now = $this->shift($this->frontDesk, '2026-10-14');
        $now->update(['starts_at' => now()->subHour(), 'ends_at' => now()->addHours(2)]);
        $this->on($now, $this->robin);
        $later = $this->shift($this->frontDesk, '2026-10-14');
        $this->on($later, $this->robin, AssignmentType::Shadow);

        $this->assertSame(['shifts' => 1, 'regulars' => 0], app(DeactivateUser::class)->handle($this->coordinator, $this->robin));

        $this->assertTrue($now->assignments()->where('user_id', $this->robin->id)->exists());
        $this->assertFalse($later->assignments()->where('user_id', $this->robin->id)->exists());
    }

    public function test_putting_a_turned_off_person_on_a_shift_rechecks_under_the_lock(): void
    {
        $shift = $this->shift($this->frontDesk, '2026-10-21');
        $stale = User::query()->findOrFail($this->robin->id);
        User::query()->whereKey($this->robin->id)->update(['deactivated_at' => now()]);

        try {
            app(PutOnShift::class)->handle($this->coordinator, $shift, $stale, AssignmentType::Regular);
            $this->fail('A turned-off person should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('Robin has been turned off and can\'t be put on a shift.', $e->getMessage());
        }

        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id]);

        try {
            app(AddRegular::class)->handle($this->coordinator, $slot, $stale, '2026-10-15');
            $this->fail('A turned-off person should be refused.');
        } catch (DomainException $e) {
            $this->assertSame('Robin has been turned off and can\'t be made a regular.', $e->getMessage());
        }

        $this->assertSame(0, Assignment::query()->where('user_id', $this->robin->id)->count());
        $this->assertSame(0, SlotRegular::query()->where('user_id', $this->robin->id)->count());
    }
}
