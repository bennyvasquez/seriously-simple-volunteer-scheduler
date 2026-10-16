<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RevokeRole;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class RolesTest extends TestCase
{
    use RefreshDatabase;

    private function grant(?User $actor, User $user, Role $role, ?Area $area = null): void
    {
        app(GrantRole::class)->handle($actor, $user, $role, $area);
    }

    public function test_a_new_person_is_a_volunteer_with_no_roles(): void
    {
        $user = User::factory()->create();

        $this->assertFalse($user->isCoordinator());
        $this->assertFalse($user->requiresTwoFactor());
        $this->assertTrue($user->isActive());
    }

    public function test_everyone_with_a_role_needs_two_factor(): void
    {
        $admin = User::factory()->create();
        $coordinator = User::factory()->create();
        $lead = User::factory()->create();
        $this->grant(null, $admin, Role::Admin);
        $this->grant(null, $coordinator, Role::Coordinator);
        $this->grant(null, $lead, Role::AreaLead, Area::factory()->create());

        $this->assertTrue($admin->isCoordinator());
        $this->assertTrue($admin->requiresTwoFactor());
        $this->assertTrue($coordinator->requiresTwoFactor());
        $this->assertTrue($lead->requiresTwoFactor());
    }

    public function test_an_area_lead_leads_only_their_area(): void
    {
        $frontDesk = Area::factory()->create();
        $events = Area::factory()->create();
        $lead = User::factory()->create();
        $this->grant(null, $lead, Role::AreaLead, $frontDesk);

        $this->assertTrue($lead->hasRole(Role::AreaLead, $frontDesk));
        $this->assertFalse($lead->hasRole(Role::AreaLead, $events));
        $this->assertFalse($lead->isCoordinator());
    }

    public function test_granting_a_role_twice_keeps_one_and_logs_once(): void
    {
        $user = User::factory()->create();
        $this->grant(null, $user, Role::Coordinator);
        $this->grant(null, $user, Role::Coordinator);

        $this->assertSame(1, $user->roles()->count());
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'role.granted')->count());
    }

    public function test_an_area_lead_needs_an_area(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->grant(null, User::factory()->create(), Role::AreaLead);
    }

    public function test_other_roles_cannot_have_an_area(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->grant(null, User::factory()->create(), Role::Coordinator, Area::factory()->create());
    }

    public function test_a_coordinator_can_grant_area_lead_but_not_coordinator(): void
    {
        $coordinator = User::factory()->create();
        $this->grant(null, $coordinator, Role::Coordinator);
        $volunteer = User::factory()->create();

        $this->grant($coordinator, $volunteer, Role::AreaLead, Area::factory()->create());
        $this->assertTrue($volunteer->roles()->exists());

        $this->expectException(AuthorizationException::class);
        $this->grant($coordinator, $volunteer, Role::Coordinator);
    }

    public function test_a_volunteer_cannot_grant_roles(): void
    {
        $this->expectException(AuthorizationException::class);

        $this->grant(User::factory()->create(), User::factory()->create(), Role::AreaLead, Area::factory()->create());
    }

    public function test_revoking_a_role_takes_effect_immediately(): void
    {
        $admin = User::factory()->create();
        $this->grant(null, $admin, Role::Admin);
        $coordinator = User::factory()->create();
        $this->grant($admin, $coordinator, Role::Coordinator);

        app(RevokeRole::class)->handle($admin, $coordinator, Role::Coordinator);

        $this->assertFalse($coordinator->isCoordinator());
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'role.revoked')->count());
    }

    public function test_a_deactivated_person_is_not_active(): void
    {
        $this->assertFalse(User::factory()->deactivated()->create()->isActive());
    }

    public function test_a_volunteer_can_have_no_password(): void
    {
        $this->assertNull(User::factory()->withoutPassword()->create()->fresh()->password);
    }
}
