<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InvitePerson;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class PeopleListTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Area $frontDesk;

    private Area $outreach;

    private User $coordinator;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->outreach = Area::factory()->create(['name' => 'Outreach']);
        $this->coordinator = $this->withRole(Role::Coordinator, name: 'Alex Coordinator', email: 'alex@example.org');
        $this->lead = $this->withRole(Role::AreaLead, $this->frontDesk, 'Casey Lead', 'casey@example.org');
    }

    private function withRole(Role $role, ?Area $area = null, string $name = 'Someone', string $email = 'someone@example.org'): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function onShiftIn(User $person, Area $area): void
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $area, '2026-10-20', '15:30', '18:30', 1, 0, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => 'regular']);
    }

    public function test_coordinators_see_everyone_and_area_leads_see_their_people(): void
    {
        $desk = User::factory()->create(['name' => 'Avery Desk']);
        $outreachPerson = User::factory()->create(['name' => 'Parker Outreach']);
        $removed = User::factory()->create(['name' => 'Removed Person', 'is_removed_person' => true]);
        $this->onShiftIn($desk, $this->frontDesk);
        $this->onShiftIn($outreachPerson, $this->outreach);

        $this->actingAs($this->coordinator)->get(route('people.index'))
            ->assertOk()->assertSee('Avery Desk')->assertSee('Parker Outreach')->assertSee('Casey Lead')->assertDontSee('Removed Person');

        $this->actingAs($this->lead)->get(route('people.index'))
            ->assertOk()->assertSee('Avery Desk')->assertSee('Casey Lead')->assertDontSee('Parker Outreach')->assertDontSee('Removed Person')
            ->assertSee('Shift lead: Front desk')->assertDontSee('Invite someone');
    }

    public function test_someone_with_no_role_cannot_open_the_list(): void
    {
        $this->actingAs(User::factory()->create())->get(route('people.index'))->assertForbidden();
    }

    public function test_filters_narrow_the_list(): void
    {
        User::factory()->notInvited()->create(['name' => 'Drew Notinvited', 'email' => 'drew@example.org']);
        User::factory()->deactivated()->create(['name' => 'Jamie Off', 'email' => 'jamie@example.org']);
        User::factory()->create(['name' => 'Robin Active', 'email' => 'robin@example.org']);

        $this->actingAs($this->coordinator)->get(route('people.index', ['invitation' => 'not_invited']))
            ->assertSee('Drew Notinvited')->assertDontSee('Robin Active')->assertDontSee('Jamie Off');
        $this->actingAs($this->coordinator)->get(route('people.index', ['show' => 'off']))
            ->assertSee('Jamie Off')->assertDontSee('Robin Active');
        $this->actingAs($this->coordinator)->get(route('people.index'))
            ->assertDontSee('Jamie Off')->assertSee('Robin Active');
        $this->actingAs($this->coordinator)->get(route('people.index', ['show' => 'all']))
            ->assertSee('Jamie Off')->assertSee('Robin Active');
        $this->actingAs($this->coordinator)->get(route('people.index', ['q' => 'Robin']))
            ->assertSee('Robin Active')->assertDontSee('Drew Notinvited');
        $this->actingAs($this->coordinator)->get(route('people.index', ['q' => 'drew@example']))
            ->assertSee('Drew Notinvited')->assertDontSee('Robin Active');
        $this->actingAs($this->coordinator)->get(route('people.index', ['role' => 'area_lead']))
            ->assertSee('Casey Lead')->assertDontSee('Robin Active');
        $this->actingAs($this->coordinator)->get(route('people.index', ['role' => 'volunteer']))
            ->assertSee('Robin Active')->assertDontSee('Casey Lead')->assertDontSee('Alex Coordinator');
    }

    public function test_the_area_filter_finds_leads_and_people_with_shifts_there(): void
    {
        $outreachPerson = User::factory()->create(['name' => 'Parker Outreach']);
        $this->onShiftIn($outreachPerson, $this->outreach);

        $this->actingAs($this->coordinator)->get(route('people.index', ['area' => $this->outreach->id]))
            ->assertSee('Parker Outreach')->assertDontSee('Casey Lead');
        $this->actingAs($this->coordinator)->get(route('people.index', ['area' => $this->frontDesk->id]))
            ->assertSee('Casey Lead')->assertDontSee('Parker Outreach');
    }

    public function test_the_count_line_leaves_out_zero_parts(): void
    {
        User::factory()->notInvited()->count(2)->create();

        $this->actingAs($this->coordinator)->get(route('people.index'))
            ->assertSee('4 people, 2 not invited yet')->assertDontSee('invitations waiting')->assertDontSee('invitation waiting');
    }

    public function test_an_area_lead_cannot_search_by_email_or_invite(): void
    {
        $desk = User::factory()->create(['name' => 'Avery Desk', 'email' => 'avery@example.org']);
        $this->onShiftIn($desk, $this->frontDesk);

        $this->actingAs($this->lead)->get(route('people.index', ['q' => 'example.org']))
            ->assertOk()->assertDontSee('Avery Desk');
        $this->actingAs($this->lead)->get(route('people.index', ['q' => 'Avery']))
            ->assertSee('Avery Desk');
        $this->actingAs($this->lead)->get(route('people.invite'))->assertForbidden();
        $this->actingAs($this->lead)->post(route('people.invite-everyone.store'))->assertForbidden();
        $this->actingAs($this->lead)->get(route('people.invite-everyone'))->assertForbidden();
    }

    public function test_waiting_and_ran_out_show_when_the_invitation_was_sent(): void
    {
        $drew = User::factory()->notInvited()->create(['name' => 'Drew Waiting']);
        app(InvitePerson::class)->handle($this->coordinator, $drew);

        $this->actingAs($this->coordinator)->get(route('people.index'))
            ->assertSee('Waiting')->assertSee('Sent Oct 14')->assertSee('1 invitation waiting');
    }

    public function test_the_list_uses_a_fixed_number_of_queries_and_pages_by_fifty(): void
    {
        User::factory()->count(60)->create();
        // The first request pays for one-time work such as the organization lookup, so measure the second and third.
        $this->actingAs($this->coordinator)->get(route('people.index'));
        \DB::enableQueryLog();
        $first = $this->actingAs($this->coordinator)->get(route('people.index'));
        $queries = count(\DB::getQueryLog());
        \DB::flushQueryLog();
        $first->assertOk()->assertSee('page=2');

        User::factory()->count(20)->create();
        \DB::flushQueryLog();
        $this->actingAs($this->coordinator)->get(route('people.index'));
        $this->assertSame($queries, count(\DB::getQueryLog()));
    }
}
