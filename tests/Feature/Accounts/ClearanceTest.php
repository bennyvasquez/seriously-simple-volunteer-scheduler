<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\ClearPerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RevokeRole;
use App\ChangeLog\Describe;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearanceTest extends TestCase
{
    use RefreshDatabase;

    private Area $frontDesk;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 13:00', 'UTC'));
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->admin = User::factory()->create(['name' => 'Morgan Admin']);
        app(GrantRole::class)->handle(null, $this->admin, Role::Admin);
    }

    public function test_a_new_person_isnt_cleared(): void
    {
        $this->assertFalse(User::factory()->create()->isCleared());
        $this->assertTrue(User::factory()->cleared()->create()->isCleared());
    }

    public function test_granting_a_role_clears_someone(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example']);

        app(GrantRole::class)->handle($this->admin, $robin, Role::AreaLead, $this->frontDesk);

        $robin->refresh();
        $this->assertTrue($robin->isCleared());
        $this->assertTrue($robin->cleared_at->equalTo(now()));

        $entry = ChangeLogEntry::query()->where('action', 'user.cleared')->about($robin)->sole();
        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertSame('Morgan Admin', $entry->actor_name);
        $this->assertSame(['via' => 'role.granted'], $entry->details);
        $this->assertNull($entry->changes['cleared_at']['old']);
        $this->assertNotNull($entry->changes['cleared_at']['new']);
        $this->assertSame('Cleared Robin Example for regular shifts (with a role)', Describe::entry($entry, $this->admin));
    }

    public function test_revoking_a_role_leaves_them_cleared(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example']);
        app(GrantRole::class)->handle($this->admin, $robin, Role::AreaLead, $this->frontDesk);
        $clearedAt = $robin->fresh()->cleared_at;
        $this->travel(1)->hours();

        app(RevokeRole::class)->handle($this->admin, $robin, Role::AreaLead, $this->frontDesk);

        $this->assertTrue($robin->fresh()->cleared_at->equalTo($clearedAt));
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'user.cleared')->about($robin)->count());
    }

    public function test_granting_a_role_to_someone_cleared_logs_nothing_more(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example']);
        app(GrantRole::class)->handle($this->admin, $robin, Role::AreaLead, $this->frontDesk);
        $clearedAt = $robin->fresh()->cleared_at;
        $this->travel(1)->hours();

        app(GrantRole::class)->handle($this->admin, $robin, Role::Coordinator);

        $this->assertTrue($robin->fresh()->cleared_at->equalTo($clearedAt));
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'user.cleared')->about($robin)->count());
    }

    public function test_the_migration_clears_people_who_hold_a_role(): void
    {
        $coordinator = User::factory()->create(['name' => 'Jordan Coordinator']);
        $lead = User::factory()->create(['name' => 'Alex Lead']);
        $volunteer = User::factory()->create(['name' => 'Sam Volunteer']);
        // Roles held before clearance existed: written directly, so nothing clears them yet.
        $coordinator->roles()->create(['role' => Role::Coordinator->value]);
        $lead->roles()->create(['role' => Role::AreaLead->value, 'area_id' => $this->frontDesk->id]);
        // The admin from setUp was cleared by GrantRole; the backfill leaves them alone.
        $before = ChangeLogEntry::query()->where('action', 'user.cleared')->count();

        $this->assertSame(2, app(ClearPerson::class)->clearRoleHolders());

        $this->assertTrue($coordinator->fresh()->isCleared());
        $this->assertTrue($lead->fresh()->isCleared());
        $this->assertFalse($volunteer->fresh()->isCleared());
        $this->assertSame($before + 2, ChangeLogEntry::query()->where('action', 'user.cleared')->count());

        foreach ([$coordinator, $lead] as $person) {
            $entry = ChangeLogEntry::query()->where('action', 'user.cleared')->about($person)->sole();
            $this->assertTrue($entry->isBySystem());
            $this->assertSame(['via' => 'migration'], $entry->details);
            $this->assertNull($entry->changes['cleared_at']['old']);
            $this->assertNotNull($entry->changes['cleared_at']['new']);
            $this->assertSame('Cleared '.$person->name.' for regular shifts (already held a role)', Describe::entry($entry, $this->admin));
        }

        $this->assertSame(0, app(ClearPerson::class)->clearRoleHolders());
    }
}
