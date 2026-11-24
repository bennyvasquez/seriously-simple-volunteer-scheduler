<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\LoginLink;
use App\Models\User;
use App\Notifications\ConfirmItsYouNotification;
use App\Notifications\EmailChangeConfirmationNotification;
use App\Notifications\EmailChangedNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class EmailChangeTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private ?string $leadSecret = null;

    private function emailChangeTokenFor(User $user, string $email): string
    {
        return basename(parse_url(app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::EmailChange, $email), PHP_URL_PATH));
    }

    private function identityTokenFor(User $user): string
    {
        return basename(parse_url(app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::ConfirmIdentity), PHP_URL_PATH));
    }

    private function leadWithTwoFactor(): User
    {
        $lead = User::factory()->create(['email' => 'lead@example.org']);
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, Area::factory()->create());
        $this->leadSecret = $this->enableTwoFactor($lead);

        return $lead->fresh();
    }

    public function test_changing_an_address_waits_for_the_new_address_to_confirm(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'sam.new@example.org'])
            ->assertSessionHas('status', 'We\'ve sent a link to sam.new@example.org. Your current address keeps working until you open it.');

        $this->assertSame('sam@example.org', $sam->fresh()->email);
        Notification::assertSentOnDemand(EmailChangeConfirmationNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'sam.new@example.org');
        $this->assertSame('sam.new@example.org', LoginLink::query()->where('purpose', LoginLinkPurpose::EmailChange)->sole()->email);
    }

    public function test_the_new_address_is_lowercased(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'Sam.New@Example.org'])
            ->assertSessionHas('status', 'We\'ve sent a link to sam.new@example.org. Your current address keeps working until you open it.');

        Notification::assertSentOnDemand(EmailChangeConfirmationNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'sam.new@example.org');
    }

    public function test_the_confirmation_email_says_what_happens(): void
    {
        $mail = (new EmailChangeConfirmationNotification('https://example.org/link'))->toMail(Notification::route('mail', 'sam.new@example.org'));

        $this->assertStringContainsString('If it was you, open this link to confirm. It works once, for 60 minutes. If it wasn\'t you, ignore this email and nothing changes.', implode(' ', $mail->introLines));
    }

    public function test_opening_the_link_changes_the_address_tells_the_old_one_and_logs_it(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');

        $this->post(route('account.email.confirm', $token))->assertOk()->assertSee('Your email address is now sam.new@example.org');

        $this->assertSame('sam.new@example.org', $sam->fresh()->email);
        Notification::assertSentOnDemand(EmailChangedNotification::class, fn ($n, $channels, $notifiable) => $notifiable->routes['mail'] === 'sam@example.org' && str_contains($n->maskedAddress, 's•••@example.org'));
        $entry = ChangeLogEntry::query()->ofAction('user.email_changed')->sole();
        $this->assertSame(['old' => 'sam@example.org', 'new' => 'sam.new@example.org'], $entry->changes['email']);
        $this->assertSame($sam->id, $entry->actor_id);
        $this->assertNull($entry->details);
        $this->assertGuest();
    }

    public function test_the_old_address_hears_about_the_change_with_the_new_one_partly_hidden(): void
    {
        $mail = (new EmailChangedNotification('rosa@example.org'))->toMail(Notification::route('mail', 'sam@example.org'));

        $this->assertStringContainsString('was changed to r•••@example.org. If this wasn\'t you, contact a coordinator right away.', implode(' ', $mail->introLines));
        $this->assertStringNotContainsString('rosa', implode(' ', $mail->introLines));
    }

    public function test_opening_the_confirm_page_does_not_use_the_link(): void
    {
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');

        $this->get(route('account.email.confirm', $token))->assertOk()->assertSee('sam.new@example.org')->assertDontSee('Your email address is now');

        $this->assertSame('sam@example.org', $sam->fresh()->email);
        $this->assertNull(LoginLink::query()->sole()->used_at);
    }

    public function test_a_used_link_shows_the_expired_state(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');
        $this->post(route('account.email.confirm', $token));

        $this->get(route('account.email.confirm', $token))->assertOk()->assertSee('This link doesn\'t work anymore');
        $this->post(route('account.email.confirm', $token))->assertOk()->assertSee('This link doesn\'t work anymore');

        $this->assertSame(1, ChangeLogEntry::query()->ofAction('user.email_changed')->count());
    }

    public function test_an_address_in_use_gets_the_same_answer_and_changes_nothing(): void
    {
        Notification::fake();
        User::factory()->create(['email' => 'taken@example.org']);
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'taken@example.org'])
            ->assertSessionHas('status', 'We\'ve sent a link to taken@example.org. Your current address keeps working until you open it.');

        Notification::assertNothingSent();
        $this->assertSame(0, LoginLink::query()->where('purpose', LoginLinkPurpose::EmailChange)->count());
    }

    public function test_your_own_address_gets_a_field_error_and_changes_nothing(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->withSession(['auth.password_confirmed_at' => time()])
            ->from(route('account.email.edit'))
            ->post(route('account.email.update'), ['email' => 'SAM@example.org'])
            ->assertRedirect(route('account.email.edit'))
            ->assertSessionHasErrors(['email' => 'That\'s already your address.'])
            ->assertSessionMissing('status');

        Notification::assertNothingSent();
        $this->assertSame(0, LoginLink::query()->count());
    }

    public function test_an_address_taken_before_the_link_is_opened_changes_nothing(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');
        User::factory()->create(['email' => 'sam.new@example.org']);

        $this->post(route('account.email.confirm', $token))->assertOk()->assertSee('This link doesn\'t work anymore')->assertDontSee('Your email address is now');

        $this->assertSame('sam@example.org', $sam->fresh()->email);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.email_changed')->count());
        Notification::assertNothingSent();
    }

    public function test_a_deactivated_person_cannot_use_the_link(): void
    {
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');
        $sam->forceFill(['deactivated_at' => now()])->save();

        $this->post(route('account.email.confirm', $token))->assertOk()->assertSee('This link doesn\'t work anymore');

        $this->assertSame('sam@example.org', $sam->fresh()->email);
    }

    public function test_the_address_must_be_an_email_address(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'not an address'])
            ->assertSessionHasErrors('email');

        Notification::assertNothingSent();
    }

    public function test_someone_with_a_password_confirms_it_first(): void
    {
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->get(route('account.email.edit'))->assertRedirect(route('password.confirm'));
        $this->actingAs($sam)->post(route('account.email.update'), ['email' => 'sam.new@example.org'])->assertRedirect(route('password.confirm'));

        $this->assertSame(0, LoginLink::query()->count());
    }

    public function test_someone_with_a_password_sees_the_form_once_confirmed(): void
    {
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->withSession(['auth.password_confirmed_at' => time()])->get(route('account.email.edit'))
            ->assertOk()->assertSee('New email address')->assertDontSee('6-digit code from your app')->assertDontSee('Confirm it\'s you');
    }

    public function test_someone_without_a_password_confirms_by_email_first(): void
    {
        Notification::fake();
        $volunteer = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);

        $this->actingAs($volunteer)->get(route('account.email.edit'))->assertSee('Confirm it\'s you')->assertDontSee('New email address');
        $this->actingAs($volunteer)->post(route('account.email.identity.send'))
            ->assertRedirect(route('account.email.edit'))
            ->assertSessionHas('status', 'We\'ve emailed a link to sam@example.org. Open it to keep going.');

        Notification::assertSentTo($volunteer, ConfirmItsYouNotification::class);
    }

    public function test_someone_without_a_password_cannot_post_the_form_before_confirming(): void
    {
        Notification::fake();
        $volunteer = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);

        $this->actingAs($volunteer)->post(route('account.email.update'), ['email' => 'sam.new@example.org'])->assertRedirect(route('account.email.edit'));

        Notification::assertNothingSent();
    }

    public function test_someone_with_a_password_cannot_get_an_identity_link(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);

        $this->actingAs($sam)->post(route('account.email.identity.send'))->assertForbidden();

        Notification::assertNothingSent();
        $this->assertSame(0, LoginLink::query()->count());
    }

    public function test_the_identity_link_logs_in_confirms_and_shows_the_form(): void
    {
        $volunteer = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);
        $token = $this->identityTokenFor($volunteer);

        $this->get(route('account.email.identity', $token))->assertOk()->assertSee('Confirm it\'s you');
        $this->assertNull(LoginLink::query()->sole()->used_at);
        $this->assertGuest();

        $this->post(route('account.email.identity', $token))->assertRedirect(route('account.email.edit'));
        $this->assertAuthenticatedAs($volunteer);
        $this->assertNotNull(LoginLink::query()->sole()->used_at);

        $this->get(route('account.email.edit'))->assertOk()->assertSee('New email address');
    }

    public function test_a_used_identity_link_shows_the_expired_state(): void
    {
        $volunteer = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);
        $token = $this->identityTokenFor($volunteer);
        $this->post(route('account.email.identity', $token));
        Auth::logout();

        $this->post(route('account.email.identity', $token))->assertOk()->assertSee('This link doesn\'t work anymore');
        $this->assertGuest();
    }

    public function test_the_identity_link_does_not_count_as_a_password_confirmation_elsewhere(): void
    {
        $volunteer = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);
        $token = $this->identityTokenFor($volunteer);

        $this->post(route('account.email.identity', $token))->assertRedirect(route('account.email.edit'));

        $this->assertNull(session('auth.password_confirmed_at'));
        $this->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
        $this->assertNull($volunteer->fresh()->two_factor_secret);
    }

    public function test_an_identity_link_is_refused_once_the_person_has_a_password(): void
    {
        $volunteer = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);
        $token = $this->identityTokenFor($volunteer);
        $volunteer->forceFill(['password' => 'a long enough phrase'])->save();

        $this->get(route('account.email.identity', $token))->assertOk()->assertSee('This link doesn\'t work anymore');
        $this->post(route('account.email.identity', $token))->assertOk()->assertSee('This link doesn\'t work anymore');

        $this->assertGuest();
        $this->assertNull(session('account.email.identity_confirmed_at'));
    }

    public function test_these_links_cannot_be_used_to_log_in(): void
    {
        $sam = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);
        $changeToken = $this->emailChangeTokenFor($sam, 'sam.new@example.org');
        $identityToken = $this->identityTokenFor($sam);

        $this->get(route('login-link.show', $changeToken))->assertSee('This link doesn\'t work anymore');
        $this->post(route('login-link.consume', $changeToken))->assertRedirect(route('login'));
        $this->post(route('login-link.consume', $identityToken))->assertRedirect(route('login'));
        $this->post(route('account.email.identity', $changeToken))->assertSee('This link doesn\'t work anymore');

        $this->assertGuest();
        $this->assertSame(0, LoginLink::query()->whereNotNull('used_at')->count());
    }

    public function test_a_lead_needs_a_current_app_code(): void
    {
        $lead = $this->leadWithTwoFactor();

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])->get(route('account.email.edit'))->assertSee('6-digit code from your app');

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'lead.new@example.org', 'code' => '000000'])
            ->assertSessionHasErrors('code');

        $this->assertSame(0, LoginLink::query()->count());
    }

    public function test_a_lead_with_a_current_app_code_gets_the_link(): void
    {
        Notification::fake();
        $lead = $this->leadWithTwoFactor();

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'lead.new@example.org', 'code' => $this->currentCode($this->leadSecret)])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'We\'ve sent a link to lead.new@example.org. Your current address keeps working until you open it.');

        Notification::assertSentOnDemand(EmailChangeConfirmationNotification::class);
    }

    public function test_a_mistyped_address_does_not_use_up_the_app_code(): void
    {
        Notification::fake();
        $lead = $this->leadWithTwoFactor();
        $code = $this->currentCode($this->leadSecret);

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'lead.new@@example.org', 'code' => $code])
            ->assertSessionHasErrors('email')->assertSessionDoesntHaveErrors('code');

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'lead.new@example.org', 'code' => $code])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('status', 'We\'ve sent a link to lead.new@example.org. Your current address keeps working until you open it.');

        Notification::assertSentOnDemand(EmailChangeConfirmationNotification::class);
    }

    public function test_the_form_and_the_identity_link_have_separate_limits(): void
    {
        Notification::fake();
        $volunteer = User::factory()->withoutPassword()->create(['email' => 'sam@example.org']);

        for ($i = 0; $i < 5; $i++) {
            $this->actingAs($volunteer)->post(route('account.email.identity.send'))->assertRedirect();
        }
        $this->actingAs($volunteer)->post(route('account.email.identity.send'))->assertStatus(429);

        $this->actingAs($volunteer)->withSession(['account.email.identity_confirmed_at' => time()])
            ->post(route('account.email.update'), ['email' => 'sam.new@example.org'])
            ->assertRedirect(route('account.show'));
    }

    public function test_confirming_ends_other_sessions_and_keeps_this_one(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');
        $here = str_repeat('a', 40);
        DB::table('sessions')->insert([
            ['id' => $here, 'user_id' => $sam->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'elsewhere', 'user_id' => $sam->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($sam)->withCookie(config('session.cookie'), $here)
            ->post(route('account.email.confirm', $token))->assertOk()->assertSee('Your email address is now sam.new@example.org');

        $this->assertSame([$here], DB::table('sessions')->where('user_id', $sam->id)->pluck('id')->all());
        $entry = ChangeLogEntry::query()->ofAction('user.signed_out_everywhere')->sole();
        $this->assertTrue($entry->details['kept_current_session']);
    }

    public function test_confirming_from_a_logged_out_device_ends_every_session(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');
        DB::table('sessions')->insert(['id' => 'elsewhere', 'user_id' => $sam->id, 'payload' => '', 'last_activity' => time()]);

        $this->post(route('account.email.confirm', $token))->assertOk();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $sam->id)->count());
        $this->assertFalse(ChangeLogEntry::query()->ofAction('user.signed_out_everywhere')->sole()->details['kept_current_session']);
    }

    public function test_confirming_while_someone_else_is_logged_in_doesnt_link_to_their_account(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $robin = User::factory()->create(['email' => 'robin@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');

        // Sam opens the link on a shared computer where Robin is logged in. The page mustn't send Sam to Robin's login and security page (issue #14).
        $this->actingAs($robin)->post(route('account.email.confirm', $token))->assertOk()
            ->assertSee('Your email address is now sam.new@example.org')
            ->assertDontSee('Back to login and security')
            ->assertSee('Someone else is logged in on this device. Use the new address next time you log in on yours.');
    }

    public function test_confirming_on_your_own_device_links_back_to_login_and_security(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $token = $this->emailChangeTokenFor($sam, 'sam.new@example.org');

        $this->actingAs($sam)->post(route('account.email.confirm', $token))->assertOk()
            ->assertSee('href="'.route('account.show').'"', false)->assertSee('Back to login and security');
    }
}
