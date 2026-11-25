<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use App\Notifications\PasswordAddedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/** Adding a first password needs a recent emailed-link sign-in, the person is emailed, and it doesn't count as confirming their password (spec section 13). */
class FirstPasswordTest extends TestCase
{
    use RefreshDatabase;

    private const NEW_PASSWORD = 'a long enough phrase';

    private const REFUSAL = 'To add a password, sign in again with an emailed link, then add it within 15 minutes.';

    private function addPassword(User $user)
    {
        return $this->post(route('account.password.set'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD]);
    }

    private function signInByLink(User $user): void
    {
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);
        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))))->assertRedirect();
    }

    public function test_a_first_password_right_after_a_link_sign_in_is_saved(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->signInByLink($user);
        $this->addPassword($user)->assertRedirect(route('account.show'))->assertSessionHas('status', 'Your password is saved.');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
        $entry = ChangeLogEntry::query()->where('action', 'user.password_set')->first();
        $this->assertNotNull($entry);
        $this->assertSame($user->id, $entry->actor_id);
    }

    public function test_a_first_password_can_follow_an_invitation_link(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::Invitation);
        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))))->assertRedirect();
        $this->addPassword($user)->assertRedirect(route('account.show'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_a_first_password_can_follow_a_confirm_its_you_link(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::ConfirmIdentity);
        $this->post('/account/email/identity/'.basename(parse_url($url, PHP_URL_PATH)))->assertRedirect(route('account.email.edit'));
        $this->addPassword($user)->assertRedirect(route('account.show'));

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $user->fresh()->password));
    }

    public function test_another_persons_link_sign_in_in_the_session_does_not_count(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $other = User::factory()->create();

        $this->actingAs($user)->withSession(['auth.link_login' => ['user' => $other->id, 'at' => now()->getTimestamp()]])
            ->post(route('account.password.set'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors(['password' => self::REFUSAL]);

        $this->assertNull($user->fresh()->password);
    }

    public function test_a_first_password_long_after_a_link_sign_in_is_refused(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->signInByLink($user);
        $this->travel(16)->minutes();
        $this->addPassword($user)->assertSessionHasErrors(['password' => self::REFUSAL]);

        $this->assertNull($user->fresh()->password);
        $this->assertFalse(ChangeLogEntry::query()->where('action', 'user.password_set')->exists());
    }

    public function test_a_form_sent_too_late_shows_why_nothing_was_saved(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->signInByLink($user);
        $this->get(route('account.show'))->assertSee('Save password');
        $this->travel(16)->minutes();

        $this->from(route('account.show'))->followingRedirects()
            ->post(route('account.password.set'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertOk()
            ->assertSee(self::REFUSAL)
            ->assertSee('Log out to get a new link')
            ->assertDontSee('Save password');

        $this->assertNull($user->fresh()->password);
    }

    public function test_a_session_that_never_used_an_emailed_link_is_refused(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->actingAs($user)->post(route('account.password.set'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSessionHasErrors(['password' => self::REFUSAL]);

        $this->assertNull($user->fresh()->password);
    }

    public function test_adding_a_first_password_does_not_count_as_confirming_it(): void
    {
        $user = User::factory()->withoutPassword()->create();

        $this->signInByLink($user);
        $this->addPassword($user)->assertRedirect(route('account.show'));

        $this->get(route('account.email.edit'))->assertRedirect(route('password.confirm'));
    }

    public function test_the_person_is_emailed_when_a_password_is_added(): void
    {
        Notification::fake();
        $user = User::factory()->withoutPassword()->create();

        $this->signInByLink($user);
        $this->addPassword($user);

        Notification::assertSentTo($user, PasswordAddedNotification::class, function (PasswordAddedNotification $notification) use ($user) {
            $mail = $notification->toMail($user);

            return $mail->subject === 'A password was added to your account'
                && str_contains(implode(' ', $mail->introLines), route('password.request'));
        });
    }

    public function test_nothing_is_sent_when_the_password_is_refused(): void
    {
        Notification::fake();
        $user = User::factory()->withoutPassword()->create();

        $this->actingAs($user);
        $this->addPassword($user);

        Notification::assertNothingSent();
    }

    public function test_setup_for_a_lead_still_sets_a_password_and_confirms(): void
    {
        Notification::fake();
        $lead = User::factory()->withoutPassword()->create();
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, Area::factory()->create());

        $this->actingAs($lead)->followingRedirects()
            ->post(route('account.password.set'), ['password' => self::NEW_PASSWORD, 'password_confirmation' => self::NEW_PASSWORD])
            ->assertSee('Step 2 of 2')->assertDontSee('Confirm it\'s you');

        $this->assertTrue(Hash::check(self::NEW_PASSWORD, $lead->fresh()->password));
        $this->assertNotNull($lead->fresh()->two_factor_secret);
        $this->assertNotNull(session('auth.password_confirmed_at'));
        Notification::assertSentTo($lead, PasswordAddedNotification::class);
    }
}
