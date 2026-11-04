<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\InviteEveryone;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class InviteScreensTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Area $frontDesk;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        Organization::factory()->create();
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->coordinator = User::factory()->create(['name' => 'Alex']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
    }

    public function test_the_form_offers_volunteer_and_each_area(): void
    {
        $this->actingAs($this->coordinator)->get(route('people.invite'))
            ->assertOk()->assertSee('Volunteer')->assertSee('Shift lead for Front desk')->assertSee('They start as');
    }

    public function test_inviting_someone_from_the_form(): void
    {
        $response = $this->actingAs($this->coordinator)->post(route('people.invite.store'), [
            'name' => 'Robin Example', 'email' => 'Robin@Example.org', 'starts_as' => 'area_lead:'.$this->frontDesk->id,
        ]);

        $response->assertRedirect(route('people.index'))->assertSessionHas('status', 'Invitation sent to robin@example.org.');
        $robin = User::query()->where('email', 'robin@example.org')->firstOrFail();
        $this->assertTrue($robin->hasRole(Role::AreaLead, $this->frontDesk));
        $this->assertTrue($robin->isInvited());
        Notification::assertSentTo($robin, InvitationNotification::class);
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('user.invited')->count());
    }

    public function test_inviting_as_a_volunteer_grants_no_role(): void
    {
        $this->actingAs($this->coordinator)->post(route('people.invite.store'), ['name' => 'Robin', 'email' => 'robin@example.org', 'starts_as' => 'volunteer'])
            ->assertRedirect(route('people.index'));

        $this->assertFalse(User::query()->where('email', 'robin@example.org')->firstOrFail()->roles()->exists());
    }

    public function test_an_email_already_in_use_is_refused_on_the_field(): void
    {
        $this->actingAs($this->coordinator)->from(route('people.invite'))
            ->post(route('people.invite.store'), ['name' => 'Alex Two', 'email' => $this->coordinator->email, 'starts_as' => 'volunteer'])
            ->assertRedirect(route('people.invite'))
            ->assertSessionHasErrors(['email' => 'Someone with that email address already has an account.']);

        $this->assertSame(1, User::query()->where('email', $this->coordinator->email)->count());
    }

    public function test_a_start_that_is_not_on_the_list_makes_no_one(): void
    {
        $this->actingAs($this->coordinator)->post(route('people.invite.store'), ['name' => 'Robin', 'email' => 'robin@example.org', 'starts_as' => 'area_lead:9999'])
            ->assertSessionHasErrors('starts_as');

        $this->assertFalse(User::query()->where('email', 'robin@example.org')->exists());
    }

    public function test_inviting_everyone_twice_sends_each_person_one_invitation(): void
    {
        User::factory()->notInvited()->count(3)->create();
        User::factory()->notInvited()->deactivated()->create();

        $this->assertSame(3, app(InviteEveryone::class)->handle($this->coordinator));
        $this->assertSame(0, app(InviteEveryone::class)->handle($this->coordinator));

        $this->assertSame(3, ChangeLogEntry::query()->ofAction('user.invited')->count());
        Notification::assertCount(3);
    }

    public function test_the_invite_everyone_page_counts_and_confirms(): void
    {
        User::factory()->notInvited()->count(3)->create();

        $this->actingAs($this->coordinator)->get(route('people.invite-everyone'))
            ->assertOk()->assertSee('Invite 3 people?')->assertSee('Send 3 invitations')
            ->assertSee('Each gets an email with a link to join. It works once, for 7 days.');
        $this->actingAs($this->coordinator)->get(route('people.index'))->assertSee('Invite everyone not invited yet (3 people)');

        $this->actingAs($this->coordinator)->post(route('people.invite-everyone.store'))
            ->assertRedirect(route('people.index'))->assertSessionHas('status', 'Sent 3 invitations.');
        $this->actingAs($this->coordinator)->get(route('people.index'))->assertDontSee('Invite everyone not invited yet');
    }
}
