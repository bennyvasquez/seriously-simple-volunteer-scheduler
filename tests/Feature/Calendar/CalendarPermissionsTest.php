<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarPermissionsTest extends TestCase
{
    use RefreshDatabase;

    private function withRole(Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);

        return $user;
    }

    private function shiftIn(Area $area): Shift
    {
        return Shift::query()->create([
            'area_id' => $area->id,
            'local_date' => '2026-10-22',
            'starts_at' => '2026-10-22 16:00:00',
            'ends_at' => '2026-10-22 19:00:00',
            'people_needed' => 1,
        ]);
    }

    public function test_an_area_lead_cannot_change_another_area(): void
    {
        [$frontDesk, $events] = Area::factory()->count(2)->create();
        $lead = $this->withRole(Role::AreaLead, $frontDesk);

        $this->assertTrue($lead->can('manageSchedule', $frontDesk));
        $this->assertFalse($lead->can('manageSchedule', $events));
        $this->assertFalse($lead->can('create', Area::class));
        $this->assertFalse($lead->can('close', $frontDesk));
    }

    public function test_coordinators_manage_every_area_and_only_admins_change_settings(): void
    {
        $coordinator = $this->withRole(Role::Coordinator);
        $admin = $this->withRole(Role::Admin);

        $this->assertTrue($coordinator->can('manageSchedule', Area::factory()->create()));
        $this->assertFalse($coordinator->can('manage-settings'));
        $this->assertTrue($admin->can('manage-settings'));
    }

    public function test_volunteers_cannot_view_the_coordinator_calendar(): void
    {
        $this->assertFalse(User::factory()->create()->canViewCalendar());
    }

    public function test_shift_rules_follow_the_shifts_area(): void
    {
        [$frontDesk, $events] = Area::factory()->count(2)->create();
        $lead = $this->withRole(Role::AreaLead, $frontDesk);

        foreach (['update', 'assign', 'cancel'] as $ability) {
            $this->assertTrue($lead->can($ability, $this->shiftIn($frontDesk)));
            $this->assertFalse($lead->can($ability, $this->shiftIn($events)));
            $this->assertTrue($this->withRole(Role::Coordinator)->can($ability, $this->shiftIn($events)));
        }
    }

    public function test_leads_and_coordinators_view_the_calendar_and_lead_area_ids_are_listed(): void
    {
        [$frontDesk, $events] = Area::factory()->count(2)->create();
        $lead = $this->withRole(Role::AreaLead, $frontDesk);
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, $events);

        $this->assertTrue($lead->can('viewAny', Area::class));
        $this->assertEqualsCanonicalizing([$frontDesk->id, $events->id], $lead->ledAreaIds()->all());
        $this->assertTrue($this->withRole(Role::Coordinator)->can('create', Area::class));
        $this->assertTrue($this->withRole(Role::Coordinator)->can('close', $frontDesk));
        $this->assertFalse(User::factory()->create()->can('viewAny', Area::class));
    }

    public function test_a_deactivated_user_can_do_nothing(): void
    {
        $area = Area::factory()->create();
        $shift = $this->shiftIn($area);

        foreach ([Role::Admin, Role::Coordinator, Role::AreaLead] as $role) {
            $user = $this->withRole($role, $role === Role::AreaLead ? $area : null);
            $user->forceFill(['deactivated_at' => now()])->save();

            $this->assertFalse($user->canViewCalendar());
            $this->assertFalse($user->canManageArea($area));
            $this->assertFalse($user->can('viewAny', Area::class));
            $this->assertFalse($user->can('create', Area::class));
            $this->assertFalse($user->can('close', $area));
            $this->assertFalse($user->can('update', $shift));
            $this->assertFalse($user->can('manage-settings'));
        }
    }
}
