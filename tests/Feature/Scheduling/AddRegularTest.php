<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\GenerateShifts;
use App\Enums\AssignmentType;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
        $this->slot = WeeklySlot::factory()->create(['weekday' => 4, 'headcount' => 1]);
        app(GenerateShifts::class)->handle($organization);
    }

    private function addRegular(User $user, string $startsOn, ?string $endsOn = null): void
    {
        app(AddRegular::class)->handle($this->slot, $user, $startsOn, $endsOn);
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

    public function test_it_skips_shifts_someone_else_already_filled(): void
    {
        $other = User::factory()->create();
        Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail()
            ->assignments()->create(['user_id' => $other->id, 'type' => AssignmentType::Regular]);
        $user = User::factory()->create();

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(['2026-10-15', '2026-10-29'], $this->datesFor($user));
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
        $this->slot->update(['headcount' => 2]);
        Shift::query()->update(['headcount' => 2]);
        $user = User::factory()->create();
        $shift = Shift::query()->whereDate('local_date', '2026-10-22')->firstOrFail();
        $shift->assignments()->create(['user_id' => $user->id, 'type' => AssignmentType::Regular]);

        $this->addRegular($user, '2026-10-01');

        $this->assertSame(1, $shift->assignments()->where('user_id', $user->id)->count());
        $this->assertSame(['2026-10-15', '2026-10-22', '2026-10-29'], $this->datesFor($user));
    }

    public function test_the_same_person_cannot_be_added_twice_for_overlapping_dates(): void
    {
        $this->slot->update(['headcount' => 2]);
        $user = User::factory()->create();
        $this->addRegular($user, '2026-10-01');

        $this->expectException(DomainException::class);

        $this->addRegular($user, '2026-10-20');
    }

    public function test_a_full_slot_refuses_another_regular(): void
    {
        $this->addRegular(User::factory()->create(), '2026-10-01');

        $this->expectException(DomainException::class);

        $this->addRegular(User::factory()->create(), '2026-10-20');
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
}
