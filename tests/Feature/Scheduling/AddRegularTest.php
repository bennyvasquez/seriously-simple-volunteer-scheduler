<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\EndRegular;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\NeedsOverConfirmation;
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
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Tests\TestCase;

class AddRegularTest extends TestCase
{
    use RefreshDatabase;

    private WeeklySlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        // Thursday 15 October 2026, 10:00 in New York. Generates shifts on 15, 22 and 29 October.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/New_York'));
        $organization = Organization::factory()->create(['timezone' => 'America/New_York', 'weeks_ahead' => 3]);
        $this->slot = WeeklySlot::factory()->create(['weekday' => 4, 'people_needed' => 1]);
        app(GenerateShifts::class)->handle($organization);
    }

    private function addRegular(User $user, string $startsOn, ?string $endsOn = null): void
    {
        app(AddRegular::class)->handle(null, $this->slot, $user, $startsOn, $endsOn);
    }

    private function addAlternatingRegular(User $user, string $anchorDate, ?WeeklySlot $slot = null): void
    {
        app(AddRegular::class)->handle(null, $slot ?? $this->slot, $user, '2026-10-01', null, SlotFrequency::EveryOtherWeek, $anchorDate);
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

    public function test_it_adds_the_regular_to_shifts_already_generated(): void
    {
        $user = User::factory()->create();

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($user));
        $this->assertSame(1, $this->slot->regulars()->count());
    }

    public function test_it_skips_cancelled_shifts(): void
    {
        $user = User::factory()->create();
        Shift::query()->where('local_date', '2026-10-22')->update(['cancelled_at' => now(), 'cancel_reason' => 'Power outage']);

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(['2026-10-15', '2026-10-29'], $this->datesFor($user));
        $entry = ChangeLogEntry::query()->about($user)->ofAction('regular.added')->sole();
        $this->assertCount(2, $entry->details['added_to_shift_ids']);
    }

    public function test_it_skips_shifts_that_have_started(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 16:00', 'America/New_York'));
        $user = User::factory()->create();

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(['2026-10-22', '2026-10-29'], $this->datesFor($user));
    }

    public function test_it_stops_at_the_end_date(): void
    {
        $user = User::factory()->create();

        $this->addRegular($user, '2026-10-01', '2026-10-22');

        $this->assertSame(['2026-10-15', '2026-10-22'], $this->datesFor($user));
    }

    public function test_it_adds_the_regular_to_shifts_someone_else_already_filled(): void
    {
        $other = User::factory()->create();
        Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail()
            ->assignments()->create(['user_id' => $other->id, 'type' => AssignmentType::Regular]);
        $user = User::factory()->create();

        $this->addRegular($user, '2026-10-01');

        // Someone who took one date doesn't count as a regular, and a shift that has the people it needs still gets the new regular.
        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($user));
        $this->assertSame(['2026-10-22'], $this->datesFor($other));
    }

    public function test_shadows_do_not_take_up_room(): void
    {
        $trainee = User::factory()->create();
        Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail()
            ->assignments()->create(['user_id' => $trainee->id, 'type' => AssignmentType::Shadow]);
        $user = User::factory()->create();

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($user));
    }

    public function test_someone_already_shadowing_a_shift_can_become_its_regular(): void
    {
        $user = User::factory()->create();
        Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail()
            ->assignments()->create(['user_id' => $user->id, 'type' => AssignmentType::Shadow]);

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(1, $this->slot->regulars()->count());
        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($user));
        $this->assertSame(
            AssignmentType::Shadow,
            Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail()
                ->assignments()->where('user_id', $user->id)->firstOrFail()->type,
        );
    }

    public function test_someone_who_took_one_date_can_become_the_regular(): void
    {
        $this->slot->update(['people_needed' => 2]);
        Shift::query()->update(['people_needed' => 2]);
        $user = User::factory()->create();
        $shift = Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail();
        $shift->assignments()->create(['user_id' => $user->id, 'type' => AssignmentType::Regular]);

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(1, $shift->assignments()->where('user_id', $user->id)->count());
        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($user));
    }

    public function test_the_same_person_cannot_be_added_twice_for_overlapping_dates(): void
    {
        $this->slot->update(['people_needed' => 2]);
        $user = User::factory()->create();
        $this->addRegular($user, '2026-10-01');

        $this->expectException(DomainException::class);

        $this->addRegular($user, '2026-10-20');
    }

    public function test_a_slot_that_has_the_people_it_needs_asks_before_taking_another_regular(): void
    {
        $casey = User::factory()->create();
        $robin = User::factory()->create();
        $this->addRegular($casey, '2026-10-01');

        try {
            $this->addRegular($robin, '2026-10-20');
            $this->fail('Going over people needed was allowed without a confirmation.');
        } catch (NeedsOverConfirmation $e) {
            $this->assertSame([
                ['date' => '2026-10-22', 'people' => 2, 'needed' => 1],
                ['date' => '2026-10-29', 'people' => 2, 'needed' => 1],
            ], array_slice($e->dates, 0, 2));
        }

        $this->assertSame(0, Assignment::query()->where('user_id', $robin->id)->count());
        $this->assertSame(1, $this->slot->regulars()->count());
        $this->assertFalse(ChangeLogEntry::query()->about($robin)->exists());
    }

    public function test_adding_a_regular_over_people_needed_lists_the_dates_and_asks_first(): void
    {
        $casey = User::factory()->create();
        $robin = User::factory()->create();
        $this->addRegular($casey, '2026-10-01');
        $regular = new SlotRegular(['user_id' => $robin->id, 'frequency' => SlotFrequency::Weekly, 'starts_on' => '2026-10-22']);
        $regular->weeklySlot()->associate($this->slot);

        $dates = app(AddRegular::class)->overDates($this->slot, $regular);

        $this->assertSame(['2026-10-22', '2026-10-29'], array_slice(array_column($dates, 'date'), 0, 2));
        $this->assertSame([2, 1], [$dates[0]['people'], $dates[0]['needed']]);

        app(AddRegular::class)->handle(null, $this->slot, $robin, '2026-10-22', overConfirmed: true);

        $this->assertSame(['2026-10-22', '2026-10-29'], $this->datesFor($robin));
        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($casey));
        $this->assertSame(2, Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail()->assignments()->count());
        $this->assertSame(2, ChangeLogEntry::query()->about($robin)->ofAction('assignment.added')->count());
        $this->assertSame(1, ChangeLogEntry::query()->about($robin)->ofAction('regular.added')->count());
    }

    public function test_alternating_regulars_do_not_count_as_over(): void
    {
        $this->addAlternatingRegular(User::factory()->create(), '2026-10-15');
        $robin = new SlotRegular(['user_id' => User::factory()->create()->id, 'frequency' => SlotFrequency::EveryOtherWeek, 'anchor_date' => '2026-10-22', 'starts_on' => '2026-10-01']);
        $robin->weeklySlot()->associate($this->slot);

        $this->assertSame([], app(AddRegular::class)->overDates($this->slot, $robin));
    }

    public function test_a_new_regular_can_follow_one_who_is_leaving(): void
    {
        $leaving = User::factory()->create();
        $joining = User::factory()->create();
        $this->addRegular($leaving, '2026-10-01', '2026-10-15');

        $this->addRegular($joining, '2026-10-16');

        $this->assertSame(['2026-10-15'], $this->datesFor($leaving));
        $this->assertSame(['2026-10-22', '2026-10-29'], $this->datesFor($joining));
    }

    public function test_an_end_date_before_the_start_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->addRegular(User::factory()->create(), '2026-10-22', '2026-10-15');
    }

    public function test_two_people_can_alternate_weeks_on_one_slot(): void
    {
        $first = User::factory()->create();
        $second = User::factory()->create();

        $this->addAlternatingRegular($first, '2026-10-15');
        $this->addAlternatingRegular($second, '2026-10-22');

        $this->assertSame(['2026-10-15', '2026-10-29'], $this->datesFor($first));
        $this->assertSame(['2026-10-22'], $this->datesFor($second));
    }

    public function test_a_weekly_regular_asks_first_when_alternating_weeks_have_the_people_needed(): void
    {
        $this->addAlternatingRegular(User::factory()->create(), '2026-10-15');
        $this->addAlternatingRegular(User::factory()->create(), '2026-10-22');

        $this->expectException(NeedsOverConfirmation::class);

        $this->addRegular(User::factory()->create(), '2026-10-01');
    }

    public function test_an_alternating_regular_asks_first_on_weeks_already_taken(): void
    {
        $this->addAlternatingRegular(User::factory()->create(), '2026-10-15');

        $this->expectException(NeedsOverConfirmation::class);

        // 29 October is two weeks after 15 October, so it's the same set of weeks.
        $this->addAlternatingRegular(User::factory()->create(), '2026-10-29');
    }

    public function test_people_needed_is_checked_date_by_date(): void
    {
        // Shifts copy people needed when generated, so give the existing ones room too.
        $this->slot->update(['people_needed' => 2]);
        Shift::query()->update(['people_needed' => 2]);
        $this->addRegular(User::factory()->create(), '2026-10-01', '2026-10-15');
        $this->addRegular(User::factory()->create(), '2026-10-20');
        $user = User::factory()->create();

        // Never more than two regulars on any one date, so this is allowed.
        $this->addRegular($user, '2026-10-01');

        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($user));
    }

    public function test_an_alternating_regular_needs_an_anchor_date(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(AddRegular::class)->handle(null, $this->slot, User::factory()->create(), '2026-10-01', null, SlotFrequency::EveryOtherWeek);
    }

    public function test_an_anchor_on_a_different_weekday_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        // 16 October is a Friday; the slot is on Thursdays.
        $this->addAlternatingRegular(User::factory()->create(), '2026-10-16');
    }

    public function test_alternating_regulars_are_refused_on_an_every_other_week_slot(): void
    {
        $slot = WeeklySlot::factory()->everyOtherWeek('2026-10-15')->create();

        $this->expectException(InvalidArgumentException::class);

        $this->addAlternatingRegular(User::factory()->create(), '2026-10-15', $slot);
    }

    public function test_people_needed_is_checked_for_a_slot_that_starts_later(): void
    {
        $slot = WeeklySlot::factory()->create(['weekday' => 4, 'people_needed' => 1, 'valid_from' => '2027-01-07']);
        app(AddRegular::class)->handle(null, $slot, User::factory()->create(), '2026-10-15');

        $this->expectException(NeedsOverConfirmation::class);

        app(AddRegular::class)->handle(null, $slot, User::factory()->create(), '2026-10-15');
    }

    public function test_a_weekly_regular_does_not_keep_an_anchor_date(): void
    {
        $regular = app(AddRegular::class)->handle(null, $this->slot, User::factory()->create(), '2026-10-01', null, SlotFrequency::Weekly, '2026-10-15');

        $this->assertNull($regular->fresh()->anchor_date);
    }

    public function test_adding_a_regular_is_logged_about_the_person(): void
    {
        $coordinator = User::factory()->create(['name' => 'Alex']);
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $robin = User::factory()->create();

        $regular = app(AddRegular::class)->handle($coordinator, $this->slot, $robin, '2026-10-01');

        $entry = ChangeLogEntry::query()->about($robin)->ofAction('regular.added')->sole();
        $this->assertSame($coordinator->id, $entry->actor_id);
        $this->assertTrue($regular->is($entry->subject));
        $this->assertSame('2026-10-01 00:00:00', $entry->changes['starts_on']['new']);
        $this->assertSame($this->slot->id, $entry->details['weekly_slot_id']);
    }

    public function test_a_refused_regular_logs_nothing(): void
    {
        $robin = User::factory()->create();
        app(AddRegular::class)->handle(null, $this->slot, $robin, '2026-10-01');

        try {
            app(AddRegular::class)->handle(null, $this->slot, $robin, '2026-10-01');
        } catch (DomainException) {
        }

        $this->assertSame(1, ChangeLogEntry::query()->ofAction('regular.added')->count());
    }

    public function test_it_locks_the_shifts_it_fills_and_skips_cancelled_ones(): void
    {
        Shift::query()->where('local_date', '2026-10-22')->update(['cancelled_at' => now(), 'cancel_reason' => 'Power outage']);
        Shift::query()->whereDate('local_date', '2026-10-29')->firstOrFail()
            ->assignments()->create(['user_id' => User::factory()->create()->id, 'type' => AssignmentType::Regular]);
        $user = User::factory()->create();

        DB::enableQueryLog();
        $regular = app(AddRegular::class)->handle(null, $this->slot, $user, '2026-10-01');
        $queries = collect(DB::getQueryLog())->pluck('query');

        $this->assertSame(['2026-10-15', '2026-10-29'], $this->datesFor($user));
        $this->assertSame(2, $regular->added_to_shifts);
        $this->assertTrue($queries->contains(fn (string $sql) => str_starts_with($sql, 'select * from `shifts`') && str_contains($sql, 'order by `local_date` asc') && str_ends_with($sql, 'for update')));
    }

    /** A one-time shift in another area on a local date and time in New York, with $user on it. */
    private function otherShift(User $user, string $date, string $start, string $end, bool $cancelled = false): Shift
    {
        $shift = Shift::query()->create([
            'area_id' => (Area::query()->firstWhere('name', 'Wood shop') ?? Area::factory()->create(['name' => 'Wood shop']))->id,
            'local_date' => $date,
            'starts_at' => CarbonImmutable::parse("{$date} {$start}", 'America/New_York')->utc(),
            'ends_at' => CarbonImmutable::parse("{$date} {$end}", 'America/New_York')->utc(),
            'people_needed' => 1,
            'shadow_spots' => 0,
            'cancelled_at' => $cancelled ? now() : null,
        ]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $user->id, 'type' => AssignmentType::Regular]);

        return $shift;
    }

    public function test_it_skips_dates_that_overlap_another_shift_they_are_on(): void
    {
        $user = User::factory()->create();
        // The slot runs 3:30 to 6:30pm.
        $this->otherShift($user, '2026-10-22', '18:30', '20:00'); // Starts as the slot ends: no overlap.
        $this->otherShift($user, '2026-10-29', '16:00', '17:00');
        $this->otherShift($user, '2026-10-15', '16:00', '17:00', cancelled: true); // Cancelled shifts don't count.

        $regular = app(AddRegular::class)->handle(null, $this->slot, $user, '2026-10-01');

        $onSlot = Shift::query()->where('weekly_slot_id', $this->slot->id)
            ->whereHas('assignments', fn ($query) => $query->where('user_id', $user->id))
            ->orderBy('local_date')->get()->map(fn (Shift $shift) => $shift->local_date->toDateString())->all();
        $this->assertSame(['2026-10-15', '2026-10-22'], $onSlot);
        $this->assertSame(2, $regular->added_to_shifts);
        $this->assertSame(['2026-10-29'], $regular->skipped_overlaps);
    }

    public function test_each_shift_filled_is_logged_against_the_shift_and_about_the_person(): void
    {
        $coordinator = User::factory()->create(['name' => 'Alex']);
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $robin = User::factory()->create();

        app(AddRegular::class)->handle($coordinator, $this->slot, $robin, '2026-10-01');

        foreach (Shift::query()->where('weekly_slot_id', $this->slot->id)->get() as $shift) {
            $assignment = $shift->assignments()->where('user_id', $robin->id)->sole();
            $entry = ChangeLogEntry::query()->forSubject($shift)->ofAction('assignment.added')->sole();
            $this->assertSame($coordinator->id, $entry->actor_id);
            $this->assertSame($robin->id, $entry->affected_user_id);
            $this->assertSame(['assignment_id' => $assignment->id, 'type' => 'regular'], $entry->details);
            $this->assertSame($robin->id, $entry->changes['user_id']['new']);
            $this->assertSame($shift->id, $entry->changes['shift_id']['new']);
        }
        $this->assertSame(3, ChangeLogEntry::query()->about($robin)->ofAction('assignment.added')->count());
    }

    public function test_adding_a_regular_after_their_end_does_not_put_them_back(): void
    {
        Organization::current()->update(['weeks_ahead' => 6]);
        app(GenerateShifts::class)->handle(Organization::current());
        $coordinator = User::factory()->create(['name' => 'Alex']);
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $robin = User::factory()->create();
        $first = app(AddRegular::class)->handle(null, $this->slot, $robin, '2026-10-01');

        // The area and slot locks AddRegular takes match EndRegular's order (area, slot, regular, shifts), checked in review because a race can't be tested reliably.
        app(EndRegular::class)->handle($coordinator, $first, '2026-10-29');
        app(AddRegular::class)->handle(null, $this->slot, $robin, '2026-11-12');

        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29', '2026-11-12', '2026-11-19'], $this->datesFor($robin));
        $this->assertNotContains('2026-11-05', $this->datesFor($robin));
    }
}
