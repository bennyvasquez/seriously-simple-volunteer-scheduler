<?php

namespace Tests\Feature;

use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\ExampleOrganizationSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Fortify\Fortify;
use Tests\TestCase;

/**
 * The browser tests start from this seeder, so a schema or action change that breaks it fails here, in the fast suite, too.
 */
class ExampleOrganizationSeederTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
    }

    public function test_it_seeds_a_populated_example_organization(): void
    {
        // Wednesday 18 November 2026, 9:00 in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-11-18 09:00', 'America/Detroit'));

        $this->seed(DatabaseSeeder::class);

        $organization = Organization::current();
        $this->assertSame('America/Detroit', $organization->timezone);
        $this->assertSame(4, $organization->weeks_ahead);

        $this->assertSame(['Electronics', 'Front desk', 'Woodshop'], Area::query()->orderBy('name')->pluck('name')->all());
        $this->assertSame('Shift lead', Area::query()->where('name', 'Front desk')->value('lead_name'));

        $this->assertSame(7, WeeklySlot::query()->count());
        $this->assertSame(2, WeeklySlot::query()->where('frequency', 'every_other_week')->count());

        // Shifts four weeks ahead, from today through the 27th day after it.
        $shifts = Shift::query()->with('assignments')->get();
        $this->assertSame('2026-11-18', $shifts->min('local_date')->toDateString());
        $this->assertSame('2026-12-15', $shifts->whereNotNull('weekly_slot_id')->max('local_date')->toDateString());
        $this->assertSame(2, $shifts->whereNull('weekly_slot_id')->count());

        $active = $shifts->whereNull('cancelled_at');
        $regulars = fn (Shift $shift) => $shift->assignments->where('type', AssignmentType::Regular)->count();
        $this->assertTrue($active->contains(fn (Shift $shift) => $regulars($shift) >= $shift->people_needed), 'Some shifts are full.');
        $this->assertTrue($active->contains(fn (Shift $shift) => $regulars($shift) < $shift->people_needed), 'Some shifts are short.');
        $this->assertSame(1, $shifts->whereNotNull('cancelled_at')->whereNull('area_closure_id')->count());
        $this->assertSame(1, AreaClosure::query()->count());
        $this->assertSame(2, $shifts->whereNotNull('area_closure_id')->count());
        $this->assertTrue($shifts->contains(fn (Shift $shift) => $shift->assignments->contains('type', AssignmentType::Shadow)));

        // Four staff, fifteen volunteers, one not invited yet, one turned off and the shared record a removed person's history moves onto.
        $this->assertSame(22, User::query()->count());
        $this->assertSame(1, User::query()->where('is_removed_person', true)->count());
        $this->assertSame(1, User::query()->whereNull('invited_at')->where('is_removed_person', false)->count());
        $this->assertSame(1, User::query()->whereNotNull('deactivated_at')->where('is_removed_person', false)->count());
        $this->assertSame(19, User::query()->whereNotNull('email_verified_at')->whereNull('deactivated_at')->count());
        $this->assertTrue(User::query()->where('name', 'Sunny')->exists());
        $this->assertTrue(User::query()->where('name', 'like', '%Ł%')->exists());
        $this->assertTrue(User::query()->where('name', 'like', '%Ş%')->exists());
        $this->assertSame(0, User::query()->where('email', 'not like', '%@example.org')->where('is_removed_person', false)->count());

        $admin = User::query()->where('email', ExampleOrganizationSeeder::ADMIN)->sole();
        $this->assertTrue($admin->hasRole(Role::Admin));
        $this->assertTrue(User::query()->where('email', ExampleOrganizationSeeder::COORDINATOR)->sole()->hasRole(Role::Coordinator));
        $this->assertTrue(User::query()->where('email', ExampleOrganizationSeeder::AREA_LEAD)->sole()->hasRole(Role::AreaLead, Area::query()->where('name', 'Front desk')->sole()));

        foreach ([ExampleOrganizationSeeder::ADMIN, ExampleOrganizationSeeder::COORDINATOR, ExampleOrganizationSeeder::AREA_LEAD] as $email) {
            $staff = User::query()->where('email', $email)->sole();
            $this->assertTrue($staff->hasEnabledTwoFactorAuthentication(), $email);
            $this->assertSame(ExampleOrganizationSeeder::TWO_FACTOR_SECRET, Fortify::currentEncrypter()->decrypt($staff->two_factor_secret), $email);
        }

        $needsSetup = User::query()->where('email', ExampleOrganizationSeeder::NEEDS_SETUP)->sole();
        $this->assertTrue($needsSetup->requiresTwoFactor());
        $this->assertFalse($needsSetup->hasEnabledTwoFactorAuthentication());

        foreach ([ExampleOrganizationSeeder::ADMIN, ExampleOrganizationSeeder::COORDINATOR, ExampleOrganizationSeeder::AREA_LEAD, ExampleOrganizationSeeder::NEEDS_SETUP, ExampleOrganizationSeeder::VOLUNTEER] as $email) {
            $this->assertTrue(Hash::check(ExampleOrganizationSeeder::PASSWORD, User::query()->where('email', $email)->sole()->password), $email);
        }

        $volunteer = User::query()->where('email', ExampleOrganizationSeeder::VOLUNTEER)->sole();
        $this->assertFalse($volunteer->roles()->exists());
        $this->assertTrue($volunteer->regulars()->exists());

        // Built through the app's actions, so the change log has the same entries a live install would.
        foreach (['organization.created', 'user.created', 'user.invited', 'user.accepted_invitation', 'user.password_set', 'two_factor.enabled', 'role.granted', 'area.created', 'area.updated', 'slot.created', 'regular.added', 'assignment.added', 'shift.created', 'closure.created', 'shift.cancelled', 'user.deactivated', 'user.removed'] as $action) {
            $this->assertTrue(ChangeLogEntry::query()->where('action', $action)->exists(), "The change log has a {$action} entry.");
        }
    }

    public function test_it_seeds_at_any_time_of_day(): void
    {
        // Late in the evening, after that day's shifts have started, so the seeder has to skip today's dates.
        $this->travelTo(CarbonImmutable::parse('2026-11-17 23:30', 'America/Detroit'));

        $this->seed(ExampleOrganizationSeeder::class);

        $this->assertSame(22, User::query()->count());
        $this->assertSame(1, Shift::query()->whereNotNull('cancelled_at')->whereNull('area_closure_id')->count());
    }
}
