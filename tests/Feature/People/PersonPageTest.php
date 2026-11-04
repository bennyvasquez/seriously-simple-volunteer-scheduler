<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class PersonPageTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Area $frontDesk;

    private Area $outreach;

    private User $admin;

    private User $coordinator;

    private User $frontDeskLead;

    private User $frontDeskRegular;

    private User $outreachVolunteer;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->outreach = Area::factory()->create(['name' => 'Outreach']);
        $this->admin = $this->withRole(Role::Admin, null, 'Morgan Admin', 'morgan@example.org');
        $this->coordinator = $this->withRole(Role::Coordinator, null, 'Alex Coordinator', 'alex@example.org');
        $this->frontDeskLead = $this->withRole(Role::AreaLead, $this->frontDesk, 'Casey Lead', 'casey@example.org');
        $this->frontDeskRegular = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
        $this->outreachVolunteer = User::factory()->create(['name' => 'Sam Outreach', 'email' => 'sam@example.org']);
        $this->onShiftIn($this->frontDeskRegular, $this->frontDesk);
        $this->onShiftIn($this->outreachVolunteer, $this->outreach);
    }

    private function withRole(Role $role, ?Area $area, string $name, string $email): User
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

    public function test_the_page_shows_the_person_and_their_login(): void
    {
        $this->frontDeskRegular->forceFill(['last_login_at' => now()->subDay(), 'last_seen_at' => now()])->save();

        $this->actingAs($this->coordinator)->get(route('people.show', $this->frontDeskRegular))
            ->assertOk()
            ->assertSee('Robin Example')
            ->assertSee('robin@example.org')
            ->assertSee('Joined')
            ->assertSee('Accepted')
            ->assertSee('Logs in with')
            ->assertSee('Password')
            ->assertSee('Turn off this account')
            ->assertSee("Robin is logged out everywhere and can't log in. Their history stays. Turning off doesn't take them off future shifts yet, so check the calendar for their shifts.", false)
            ->assertDontSee('Login and security');

        $this->actingAs($this->admin)->get(route('people.show', $this->frontDeskRegular))->assertOk()->assertSee('Login and security');
    }

    public function test_logs_in_with_email_links_when_there_is_no_password(): void
    {
        $person = User::factory()->withoutPassword()->create();
        $this->onShiftIn($person, $this->frontDesk);

        $this->actingAs($this->coordinator)->get(route('people.show', $person))->assertOk()->assertSee('Email links');
    }

    public function test_an_area_lead_cannot_open_someone_outside_their_areas(): void
    {
        $this->actingAs($this->frontDeskLead)->get(route('people.show', $this->outreachVolunteer))->assertNotFound();
        $this->actingAs($this->frontDeskLead)->get(route('people.show', 999999))->assertNotFound();
        $this->actingAs($this->frontDeskLead)->get(route('people.show', $this->frontDeskRegular))->assertOk()->assertDontSee('Turn off this account');
    }

    public function test_an_area_lead_sees_roles_and_login_but_no_controls_or_changes(): void
    {
        $this->actingAs($this->frontDeskLead)->get(route('people.show', $this->frontDeskRegular))
            ->assertOk()
            ->assertSee('Roles')
            ->assertSee('Logs in with')
            ->assertDontSee('Add a role')
            ->assertDontSee('Login and security')
            ->assertDontSee('Changes about')
            ->assertDontSee('Turn off this account');
    }

    public function test_the_removed_person_and_non_numeric_ids_are_not_found(): void
    {
        $removed = User::factory()->removedPerson()->create();

        $this->actingAs($this->admin)->get(route('people.show', $removed))->assertNotFound();
        $this->actingAs($this->admin)->get('/people/nobody')->assertNotFound();
    }

    public function test_the_invite_pages_are_not_treated_as_a_person(): void
    {
        $this->actingAs($this->coordinator)->get(route('people.invite'))->assertOk()->assertSee('Invite');
    }

    public function test_a_coordinator_cannot_grant_admin(): void
    {
        $this->actingAs($this->coordinator)->post(route('people.roles.store', $this->frontDeskRegular), ['role' => 'admin'])->assertForbidden();
        $this->assertFalse($this->frontDeskRegular->hasRole(Role::Admin));
    }

    public function test_roles_can_be_added_and_removed(): void
    {
        $page = route('people.show', $this->frontDeskRegular);

        $this->actingAs($this->coordinator)->get($page)->assertSee('Add a role')->assertSee('Shift lead for Front desk');

        $this->actingAs($this->coordinator)->from($page)->post(route('people.roles.store', $this->frontDeskRegular), ['role' => 'area_lead', 'area' => $this->frontDesk->id])->assertRedirect($page);
        $this->actingAs($this->coordinator)->get($page)->assertSee('Shift lead: Front desk');
        $this->assertTrue($this->frontDeskRegular->hasRole(Role::AreaLead, $this->frontDesk));

        $this->actingAs($this->coordinator)->post(route('people.roles.destroy', $this->frontDeskRegular), ['role' => 'area_lead', 'area' => $this->frontDesk->id])->assertRedirect($page);
        $this->assertFalse($this->frontDeskRegular->hasRole(Role::AreaLead, $this->frontDesk));

        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'role.granted')->about($this->frontDeskRegular)->count());
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'role.revoked')->about($this->frontDeskRegular)->count());
    }

    public function test_the_add_a_role_form_accepts_the_combined_choice(): void
    {
        $this->actingAs($this->coordinator)->post(route('people.roles.store', $this->frontDeskRegular), ['role' => 'area_lead:'.$this->outreach->id])->assertRedirect();
        $this->assertTrue($this->frontDeskRegular->hasRole(Role::AreaLead, $this->outreach));

        $this->actingAs($this->admin)->post(route('people.roles.store', $this->frontDeskRegular), ['role' => 'coordinator'])->assertRedirect();
        $this->assertTrue($this->frontDeskRegular->hasRole(Role::Coordinator));
    }

    public function test_bad_role_choices_are_refused(): void
    {
        $this->actingAs($this->admin)->post(route('people.roles.store', $this->frontDeskRegular), ['role' => 'area_lead'])->assertSessionHasErrors('role');
        $this->actingAs($this->admin)->post(route('people.roles.store', $this->frontDeskRegular), ['role' => 'wizard'])->assertSessionHasErrors('role');
        $this->assertSame(0, $this->frontDeskRegular->roles()->count());
    }

    public function test_an_area_lead_cannot_change_roles_or_turn_people_off(): void
    {
        $this->actingAs($this->frontDeskLead)->post(route('people.roles.store', $this->frontDeskRegular), ['role' => 'area_lead', 'area' => $this->frontDesk->id])->assertForbidden();
        $this->actingAs($this->frontDeskLead)->post(route('people.turn-off', $this->frontDeskRegular))->assertForbidden();
        $this->actingAs($this->frontDeskLead)->post(route('people.invite.send', $this->frontDeskRegular))->assertForbidden();
        $this->actingAs($this->frontDeskLead)->post(route('people.turn-off', $this->outreachVolunteer))->assertNotFound();
    }

    public function test_inviting_and_turning_off_from_the_page(): void
    {
        $person = User::factory()->notInvited()->create(['name' => 'Jules Example', 'email' => 'jules@example.org']);
        $page = route('people.show', $person);

        $this->actingAs($this->coordinator)->get($page)->assertSee('Not invited yet')->assertSee('Invite Jules');
        $this->actingAs($this->coordinator)->post(route('people.invite.send', $person))->assertRedirect($page);
        $this->assertTrue($person->fresh()->isInvited());

        $this->actingAs($this->coordinator)->get($page)->assertSee('Invitation waiting')->assertSee('Send a new invitation')->assertSee('Sending a new one makes the old link stop working.');

        $this->actingAs($this->coordinator)->post(route('people.turn-off', $person))->assertRedirect($page);
        $this->assertFalse($person->fresh()->isActive());
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'user.deactivated')->about($person)->count());

        $this->actingAs($this->coordinator)->get($page)->assertDontSee('Turn off this account');
        $this->actingAs($this->coordinator)->from($page)->post(route('people.invite.send', $person))->assertRedirect($page)->assertSessionHasErrors('invite');
    }

    public function test_a_coordinator_cannot_turn_off_an_admin_or_themselves(): void
    {
        $this->actingAs($this->coordinator)->post(route('people.turn-off', $this->admin))->assertForbidden();
        $this->actingAs($this->coordinator)->post(route('people.turn-off', $this->coordinator))->assertForbidden();
        $this->assertTrue($this->admin->fresh()->isActive());
        $this->assertTrue($this->coordinator->fresh()->isActive());

        $this->actingAs($this->admin)->post(route('people.turn-off', $this->coordinator))->assertRedirect();
        $this->assertFalse($this->coordinator->fresh()->isActive());
    }

    public function test_a_coordinator_sees_no_controls_on_an_admins_page(): void
    {
        $this->actingAs($this->coordinator)->get(route('people.show', $this->admin))
            ->assertOk()->assertDontSee('Add a role')->assertDontSee('Turn off this account');
    }

    public function test_the_changes_panel_lists_entries_about_the_person(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 09:00', 'America/Detroit'));
        ChangeLogEntry::record($this->coordinator, 'user.created', $this->frontDeskRegular);
        $this->travelTo(CarbonImmutable::parse('2026-10-16 09:00', 'America/Detroit'));
        ChangeLogEntry::record($this->coordinator, 'user.invitation_resent', $this->frontDeskRegular);
        $this->travelTo(CarbonImmutable::parse('2026-10-17 09:00', 'America/Detroit'));
        app(GrantRole::class)->handle($this->coordinator, $this->frontDeskRegular, Role::AreaLead, $this->frontDesk);
        ChangeLogEntry::record($this->coordinator, 'user.invited', $this->outreachVolunteer);

        $html = $this->actingAs($this->coordinator)->get(route('people.show', $this->frontDeskRegular))
            ->assertOk()
            ->assertSee('Changes about Robin')
            ->assertSee('Made Robin Example a shift lead for Front desk')
            ->assertSee('Sent Robin Example a new invitation')
            ->assertSee('Added Robin Example')
            ->assertDontSee('Invited Sam Outreach')
            ->assertSee('See all in the change log')
            ->assertSee('Alex Coordinator')
            ->getContent();

        $this->assertLessThan(strpos($html, 'Sent Robin Example a new invitation'), strpos($html, 'Made Robin Example a shift lead'));
        $this->assertLessThan(strpos($html, 'Added Robin Example'), strpos($html, 'Sent Robin Example a new invitation'));
    }

    public function test_older_changes_are_on_the_next_page(): void
    {
        foreach (range(1, 12) as $i) {
            $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit')->addMinutes($i));
            ChangeLogEntry::record($this->coordinator, $i === 1 ? 'user.created' : 'user.invitation_resent', $this->frontDeskRegular);
        }

        $page = route('people.show', $this->frontDeskRegular);
        $this->actingAs($this->coordinator)->get($page)->assertSee('Show older changes')->assertDontSee('Added Robin Example');
        $this->actingAs($this->coordinator)->get($page.'?page=2')->assertSee('Added Robin Example');
    }

    public function test_names_on_the_people_list_link_to_the_person(): void
    {
        $this->actingAs($this->coordinator)->get(route('people.index'))->assertSee('href="'.route('people.show', $this->frontDeskRegular).'"', false);
    }

    public function test_the_security_page_links_back_to_the_person(): void
    {
        $this->actingAs($this->admin)->get(route('people.security', $this->frontDeskRegular))->assertSee('href="'.route('people.show', $this->frontDeskRegular).'"', false);
    }

    public function test_roles_are_listed_in_the_same_order_as_everywhere_else(): void
    {
        $robin = $this->frontDeskRegular;
        app(GrantRole::class)->handle(null, $robin, Role::AreaLead, $this->outreach);
        app(GrantRole::class)->handle(null, $robin, Role::AreaLead, $this->frontDesk);
        app(GrantRole::class)->handle(null, $robin, Role::Coordinator);

        $this->actingAs($this->admin)->get(route('people.show', $robin))
            ->assertSeeInOrder(['Coordinator', 'Shift lead: Front desk', 'Area lead: Outreach']);
    }

    public function test_changes_that_change_nothing_do_not_claim_they_did(): void
    {
        $page = route('people.show', $this->frontDeskRegular);

        $this->actingAs($this->coordinator)->post(route('people.roles.destroy', $this->frontDeskRegular), ['role' => 'area_lead', 'area' => $this->frontDesk->id])
            ->assertRedirect($page)->assertSessionHas('status', 'Nothing changed.');

        $this->actingAs($this->coordinator)->post(route('people.turn-off', $this->frontDeskRegular));
        $this->actingAs($this->coordinator)->post(route('people.turn-off', $this->frontDeskRegular))
            ->assertRedirect($page)->assertSessionHas('status', 'Nothing changed.');
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'user.deactivated')->about($this->frontDeskRegular)->count());
    }
}
