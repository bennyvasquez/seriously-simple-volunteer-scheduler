<?php

namespace Tests\Feature\People;

use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ChangeLogScreenTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private User $admin;

    private User $coordinator;

    private User $lead;

    private User $volunteer;

    private User $robin;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $area = Area::factory()->create(['name' => 'Front desk']);
        $this->admin = $this->person(Role::Admin, null, 'Morgan Admin');
        $this->coordinator = $this->person(Role::Coordinator, null, 'Alex Coordinator');
        $this->lead = $this->person(Role::AreaLead, $area, 'Casey Lead');
        $this->volunteer = User::factory()->create(['name' => 'Vic Volunteer']);
        $this->robin = User::factory()->create(['name' => 'Robin Example']);
        $this->sam = User::factory()->create(['name' => 'Sam Example']);
    }

    private function person(Role $role, ?Area $area, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        $user->roles()->create(['role' => $role->value, 'area_id' => $area?->id]);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function entry(?User $actor, string $action, ?User $about, string $at, array $details = [], array $changes = []): ChangeLogEntry
    {
        $entry = ChangeLogEntry::record($actor, $action, $about ?? Area::query()->first(), $details, $changes, $about);
        DB::table('change_log')->where('id', $entry->id)->update(['created_at' => CarbonImmutable::parse($at, 'America/Detroit')->utc()]);

        return $entry;
    }

    public function test_only_coordinators_and_admins_see_the_change_log(): void
    {
        $this->actingAs($this->lead)->get(route('change-log.index'))->assertForbidden();
        $this->actingAs($this->volunteer)->get(route('change-log.index'))->assertForbidden();
        $this->actingAs($this->lead)->get(route('home'))->assertDontSee('Change log');
        $this->actingAs($this->coordinator)->get(route('home'))->assertSee(route('change-log.index'), false);
        $this->actingAs($this->coordinator)->get(route('calendar.week'))->assertSee(route('change-log.index'), false);
        $this->actingAs($this->admin)->get(route('change-log.index'))->assertOk()->assertSee('Change log');
    }

    public function test_a_turned_off_coordinator_does_not_get_in(): void
    {
        $this->coordinator->forceFill(['deactivated_at' => now()])->save();

        $this->assertFalse(Gate::forUser($this->coordinator->fresh())->allows('view-change-log'));
    }

    public function test_entries_show_newest_first_under_day_headings(): void
    {
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-12 09:14');
        $this->entry($this->coordinator, 'user.created', $this->sam, '2026-10-12 16:30');
        $this->entry($this->coordinator, 'user.invited', $this->sam, '2026-10-02 11:05');

        $this->actingAs($this->coordinator)->get(route('change-log.index'))
            ->assertOk()
            ->assertSeeInOrder(['Monday, October 12', '4:30pm', 'Added', 'Sam Example', '9:14am', 'Invited', 'Robin Example', 'Friday, October 2', '11:05am'])
            ->assertSee('3 changes')
            ->assertSee(route('people.show', $this->admin), false);
    }

    public function test_filters_combine(): void
    {
        $this->entry($this->admin, 'role.granted', $this->robin, '2026-10-10 10:00', ['role' => 'coordinator']);
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-11 10:00');
        $this->entry($this->coordinator, 'user.invited', $this->sam, '2026-10-11 23:30');
        $this->entry($this->coordinator, 'role.granted', $this->sam, '2026-10-12 08:00', ['role' => 'coordinator']);
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-13 08:00');

        $get = fn (array $query) => $this->actingAs($this->coordinator)->get(route('change-log.index', $query));

        $get(['by' => $this->coordinator->id])->assertSee('2 changes')->assertSee('Sam Example')->assertDontSee('Robin Example');
        $get(['about' => 'robin'])->assertSee('3 changes about robin')->assertDontSee('Sam Example');
        $get(['kind' => 'roles'])->assertSee('2 changes')->assertDontSee('Invited');
        // A name longer than 100 characters still filters instead of showing everything.
        $get(['about' => str_repeat('x', 200)])->assertSee('0 changes')->assertDontSee('Sam Example');
        $get(['by' => $this->admin->id, 'about' => 'Robin', 'kind' => 'invitations'])->assertSee('2 changes');
        $get(['from' => '2026-10-11', 'to' => '2026-10-11'])->assertSee('2 changes from Oct 11 to Oct 11')->assertSee('11:30pm')->assertSee('10:00am')->assertDontSee('Oct 10');
        $get(['from' => '2026-10-12'])->assertSee('2 changes from Oct 12');
        $get(['by' => $this->admin->id, 'about' => 'Robin', 'kind' => 'roles', 'from' => '2026-10-10', 'to' => '2026-10-10'])->assertSee('1 change ')->assertDontSee('1 changes');
        $get(['about' => 'nobody'])->assertSee('No changes match');
    }

    public function test_the_schedule_kind_includes_cover_requests(): void
    {
        foreach (['cover_request.created', 'cover_request.resent', 'cover_request.escalated', 'cover_request.filled'] as $i => $action) {
            $this->entry(null, $action, $this->robin, '2026-10-1'.$i.' 10:00', ['cover_request_id' => 1]);
        }
        $this->entry($this->admin, 'user.invited', $this->sam, '2026-10-11 11:00');

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['kind' => 'schedule']))
            ->assertSee('4 changes')->assertDontSee('Sam Example');
    }

    public function test_the_clearance_kind_holds_clearing_and_moving_back(): void
    {
        $this->entry($this->coordinator, 'user.cleared', $this->robin, '2026-10-10 10:00', ['via' => 'person_page']);
        $this->entry($this->coordinator, 'user.moved_back', $this->robin, '2026-10-11 10:00', ['release' => false]);
        $this->entry($this->admin, 'user.invited', $this->sam, '2026-10-11 11:00');

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['kind' => 'clearance']))
            ->assertSee('2 changes')
            ->assertSee('Cleared Robin Example for regular shifts')
            ->assertSee('Moved Robin Example back to shadowing')
            ->assertDontSee('Sam Example');
        $this->actingAs($this->coordinator)->get(route('change-log.index'))->assertSee('<option value="clearance"', false);
    }

    public function test_the_schedule_kind_includes_no_shows(): void
    {
        $this->entry($this->lead, 'assignment.no_show', $this->robin, '2026-10-10 10:00', [], ['no_show_at' => ['old' => null, 'new' => '2026-10-10 10:00:00']]);
        $this->entry($this->admin, 'user.invited', $this->sam, '2026-10-11 11:00');

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['kind' => 'schedule']))
            ->assertSee('1 change ')->assertDontSee('Sam Example');
    }

    public function test_the_to_date_includes_the_end_of_the_day_in_the_organization_time_zone(): void
    {
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-11 23:30');
        $this->entry($this->admin, 'user.invited', $this->sam, '2026-10-12 00:15');

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['to' => '2026-10-11']))
            ->assertSee('Robin Example')->assertDontSee('Sam Example');
        $this->actingAs($this->coordinator)->get(route('change-log.index', ['from' => '2026-10-12']))
            ->assertSee('Sam Example')->assertDontSee('Robin Example');
    }

    public function test_the_same_day_in_different_years_gets_separate_headings(): void
    {
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-12 09:00');
        $this->entry($this->admin, 'user.invited', $this->sam, '2025-10-12 09:00');

        $this->actingAs($this->coordinator)->get(route('change-log.index'))
            ->assertSeeInOrder(['Monday, October 12', 'Robin Example', 'Sunday, October 12, 2025', 'Sam Example']);
    }

    public function test_bad_filter_values_are_ignored(): void
    {
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-11 10:00');

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['by' => 'x', 'kind' => 'nonsense', 'from' => 'yesterday', 'to' => [1], 'about' => ['a']]))
            ->assertOk()->assertSee('1 change');
    }

    public function test_system_entries_and_removed_people(): void
    {
        $this->entry(null, 'shifts.generated', null, '2026-10-12 06:00', ['created' => 4, 'from' => '2026-10-12', 'to' => '2026-11-08']);
        $removed = User::factory()->removedPerson()->create();
        $this->entry($removed, 'user.invited', $this->robin, '2026-10-12 07:00');
        DB::table('change_log')->where('actor_id', $removed->id)->update(['actor_name' => ChangeLogEntry::REMOVED_PERSON]);

        $response = $this->actingAs($this->coordinator)->get(route('change-log.index'))->assertOk();

        $response->assertSee('by the system')->assertSee('Created 4 shifts from Oct 12 to Nov 8')->assertSee('by Removed person');
        $response->assertDontSee(route('people.show', $removed), false);

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['by' => 'system']))->assertSee('1 change ')->assertDontSee('by Removed person');
        $this->actingAs($this->coordinator)->get(route('change-log.index', ['by' => $removed->id]))->assertSee('1 change ')->assertDontSee('Created 4 shifts');
    }

    public function test_the_made_by_list_has_the_system_and_everyone_who_made_a_change(): void
    {
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-12 07:00');
        $this->entry($this->coordinator, 'user.invited', $this->sam, '2026-10-12 07:00');

        $this->actingAs($this->admin)->get(route('change-log.index'))
            ->assertSeeInOrder(['Anyone', 'The system', 'Alex Coordinator', 'Morgan Admin'])
            ->assertDontSee('Casey Lead</option>', false);
    }

    public function test_changes_are_shown_and_pages_hold_fifty(): void
    {
        $this->entry($this->robin, 'user.email_changed', $this->robin, '2026-10-12 09:14', [], ['email' => ['old' => 'robin@example.org', 'new' => 'robin.new@example.org']]);

        for ($i = 0; $i < 52; $i++) {
            $this->entry($this->admin, 'user.invited', $this->sam, '2026-10-13 08:00');
        }

        $this->actingAs($this->coordinator)->get(route('change-log.index'))
            ->assertSee('53 changes')
            ->assertSee('Older changes')
            ->assertDontSee('robin.new@example.org');

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['page' => 2]))
            ->assertSee('From robin@example.org to robin.new@example.org')
            ->assertSee('Newer changes')
            ->assertDontSee('Older changes');
    }

    public function test_a_page_of_entries_needs_a_fixed_number_of_queries(): void
    {
        $this->entry($this->admin, 'role.granted', $this->robin, '2026-10-10 10:00', ['role' => 'area_lead', 'area_id' => Area::query()->first()->id]);
        $this->actingAs($this->coordinator)->get(route('change-log.index'))->assertOk();

        for ($i = 0; $i < 20; $i++) {
            $this->entry($this->admin, 'role.granted', User::factory()->create(), '2026-10-10 10:00', ['role' => 'area_lead', 'area_id' => Area::query()->first()->id]);
        }

        DB::enableQueryLog();
        $this->actingAs($this->coordinator)->get(route('change-log.index'))->assertOk();
        $this->assertLessThan(30, count(DB::getQueryLog()));
    }

    public function test_the_person_page_links_to_the_change_log_about_them(): void
    {
        $this->entry($this->admin, 'user.invited', $this->robin, '2026-10-12 07:00');

        $this->actingAs($this->coordinator)->get(route('people.show', $this->robin))
            ->assertSee(route('change-log.index', ['about' => 'Robin Example']), false);
    }
}
