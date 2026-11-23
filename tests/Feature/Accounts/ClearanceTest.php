<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\ClearPerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RevokeRole;
use App\Actions\UpdateShadowingSettings;
use App\ChangeLog\Describe;
use App\Cover\ShadowProgress;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
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

    /** A shift in the given hours (UTC) on the day of the frozen clock (2026-10-14 13:00 UTC), with the person as a shadow. */
    private function shadowShift(User $person, string $starts, string $ends, bool $cancelled = false, bool $noShow = false): Assignment
    {
        $shift = Shift::create([
            'area_id' => $this->frontDesk->id, 'local_date' => CarbonImmutable::parse($starts, 'UTC')->toDateString(),
            'starts_at' => CarbonImmutable::parse($starts, 'UTC'), 'ends_at' => CarbonImmutable::parse($ends, 'UTC'),
            'people_needed' => 1, 'shadow_spots' => 1,
            'cancelled_at' => $cancelled ? now() : null, 'cancel_reason' => $cancelled ? 'Power outage' : null,
        ]);
        $assignment = Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => AssignmentType::Shadow]);

        if ($noShow) {
            $assignment->forceFill(['no_show_at' => now()])->save();
        }

        return $assignment;
    }

    public function test_only_finished_uncancelled_shadow_shifts_count(): void
    {
        $avery = User::factory()->create(['name' => 'Avery Trainee']);

        $this->shadowShift($avery, '2026-10-12 10:00', '2026-10-12 12:00');
        $this->shadowShift($avery, '2026-10-11 10:00', '2026-10-11 12:00', cancelled: true);
        $this->shadowShift($avery, '2026-10-10 10:00', '2026-10-10 12:00', noShow: true);
        $this->shadowShift($avery, '2026-10-14 12:00', '2026-10-14 14:00');
        $this->shadowShift($avery, '2026-10-15 10:00', '2026-10-15 12:00');
        // A place the person left: the assignment is deleted, so it's gone.
        $this->shadowShift($avery, '2026-10-09 10:00', '2026-10-09 12:00')->delete();

        $this->assertSame(1, ShadowProgress::done($avery));

        $history = ShadowProgress::history($avery);
        $this->assertCount(3, $history);
        $this->assertSame(['2026-10-12', '2026-10-11', '2026-10-10'], $history->map(fn (Assignment $a) => $a->shift->local_date->toDateString())->all());
        $this->assertTrue($history->every(fn (Assignment $a) => $a->shift->relationLoaded('area')));
    }

    public function test_changing_the_required_number_never_unclears_anyone(): void
    {
        Organization::factory()->create();
        $coordinator = User::factory()->create(['name' => 'Chris Coordinator']);
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $cleared = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $two = User::factory()->create(['name' => 'Avery Trainee']);
        $one = User::factory()->create(['name' => 'Quinn Trainee']);
        $this->shadowShift($cleared, '2026-10-10 10:00', '2026-10-10 12:00');
        $this->shadowShift($two, '2026-10-10 10:00', '2026-10-10 12:00');
        $this->shadowShift($two, '2026-10-11 10:00', '2026-10-11 12:00');
        $this->shadowShift($one, '2026-10-10 10:00', '2026-10-10 12:00');

        $ready = fn () => ShadowProgress::readyForReview()->pluck('name')->all();

        $this->assertSame(['Avery Trainee'], $ready());

        app(UpdateShadowingSettings::class)->handle($coordinator, 3);
        $this->assertSame([], $ready());
        $this->assertTrue($cleared->fresh()->isCleared());

        app(UpdateShadowingSettings::class)->handle($coordinator, 1);
        $this->assertEqualsCanonicalizing(['Avery Trainee', 'Quinn Trainee'], $ready());
        $this->assertTrue($cleared->fresh()->isCleared());
    }

    public function test_ready_for_review_skips_turned_off_people_and_is_one_query(): void
    {
        Organization::factory()->create();
        $off = User::factory()->create(['name' => 'Robin Example', 'deactivated_at' => now()]);
        $this->shadowShift($off, '2026-10-10 10:00', '2026-10-10 12:00');
        $this->shadowShift($off, '2026-10-11 10:00', '2026-10-11 12:00');
        $on = User::factory()->create(['name' => 'Avery Trainee']);
        $this->shadowShift($on, '2026-10-10 10:00', '2026-10-10 12:00');
        $this->shadowShift($on, '2026-10-11 10:00', '2026-10-11 12:00');

        \DB::enableQueryLog();
        $names = ShadowProgress::readyForReview()->pluck('name')->all();
        $queries = \DB::getQueryLog();
        \DB::disableQueryLog();

        $this->assertSame(['Avery Trainee'], $names);
        // One for the required number, one for the people.
        $this->assertLessThanOrEqual(2, count($queries));
    }
}
