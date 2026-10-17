<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\GrantRole;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VisiblePeopleTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);

        return $user;
    }

    private function assignedIn(Area $area, bool $past = false): User
    {
        $user = User::factory()->create();
        $start = $past ? now()->subWeek() : now()->addWeek();
        $shift = Shift::query()->create([
            'area_id' => $area->id,
            'local_date' => $start->toDateString(),
            'starts_at' => $start,
            'ends_at' => $start->copy()->addHours(3),
            'headcount' => 1,
        ]);
        $shift->assignments()->create(['user_id' => $user->id, 'type' => AssignmentType::Regular]);

        return $user;
    }

    private function regularIn(Area $area): User
    {
        $user = User::factory()->create();
        $slot = WeeklySlot::factory()->create(['area_id' => $area->id]);
        SlotRegular::query()->create(['weekly_slot_id' => $slot->id, 'user_id' => $user->id, 'starts_on' => '2026-10-01']);

        return $user;
    }

    public function test_coordinators_see_everyone(): void
    {
        $coordinator = $this->withRole(Role::Coordinator);
        User::factory()->count(3)->create();

        $this->assertSame(User::query()->count(), $coordinator->visiblePeople()->count());
    }

    public function test_an_area_lead_sees_only_people_in_their_areas(): void
    {
        [$frontDesk, $events] = Area::factory()->count(2)->create();
        $lead = $this->withRole(Role::AreaLead, $frontDesk);
        $onShift = $this->assignedIn($frontDesk);
        $regular = $this->regularIn($frontDesk);
        $pastShift = $this->assignedIn($frontDesk, past: true);
        $elsewhere = $this->assignedIn($events);
        $nowhere = User::factory()->create();

        $visible = $lead->visiblePeople()->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$lead->id, $onShift->id, $regular->id, $pastShift->id], $visible);
        $this->assertFalse($lead->canSee($elsewhere));
        $this->assertFalse($lead->canSee($nowhere));
    }

    public function test_a_lead_of_several_areas_sees_people_from_all_of_them(): void
    {
        [$frontDesk, $events, $other] = Area::factory()->count(3)->create();
        $lead = $this->withRole(Role::AreaLead, $frontDesk);
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, $events);
        $inFront = $this->assignedIn($frontDesk);
        $inEvents = $this->regularIn($events);
        $elsewhere = $this->assignedIn($other);

        $this->assertEqualsCanonicalizing([$lead->id, $inFront->id, $inEvents->id], $lead->visiblePeople()->pluck('id')->all());
        $this->assertFalse($lead->canSee($elsewhere));
    }

    public function test_a_lead_who_is_also_a_coordinator_sees_everyone(): void
    {
        $area = Area::factory()->create();
        $lead = $this->withRole(Role::AreaLead, $area);
        app(GrantRole::class)->handle(null, $lead, Role::Coordinator);
        User::factory()->count(2)->create();

        $this->assertSame(User::query()->count(), $lead->visiblePeople()->count());
    }

    public function test_deactivated_people_are_included(): void
    {
        $area = Area::factory()->create();
        $lead = $this->withRole(Role::AreaLead, $area);
        $gone = $this->assignedIn($area);
        $gone->forceFill(['deactivated_at' => now()])->save();

        $this->assertTrue($lead->canSee($gone));
    }

    public function test_a_volunteer_sees_only_themselves(): void
    {
        $volunteer = User::factory()->create();
        User::factory()->create();

        $this->assertSame([$volunteer->id], $volunteer->visiblePeople()->pluck('id')->all());
    }
}
