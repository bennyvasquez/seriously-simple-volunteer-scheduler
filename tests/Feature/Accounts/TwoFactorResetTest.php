<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\ResetTwoFactor;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\TwoFactorResetNotification;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class TwoFactorResetTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private function withRoleAndTwoFactor(Role $role, array $attributes = []): User
    {
        $user = User::factory()->create(['password' => Hash::make('correct horse battery'), 'email' => fake()->unique()->userName().'@example.org'] + $attributes);
        app(GrantRole::class)->handle(null, $user, $role, $role === Role::AreaLead ? Area::factory()->create(['name' => 'Front desk']) : null);
        $this->enableTwoFactor($user);

        return $user->fresh();
    }

    private function adminWithTwoFactor(array $attributes = []): User
    {
        return $this->withRoleAndTwoFactor(Role::Admin, $attributes);
    }

    private function coordinatorWithTwoFactor(): User
    {
        return $this->withRoleAndTwoFactor(Role::Coordinator);
    }

    private function leadWithTwoFactor(array $attributes = []): User
    {
        return $this->withRoleAndTwoFactor(Role::AreaLead, $attributes);
    }

    public function test_an_admin_resets_someones_two_factor(): void
    {
        Notification::fake();
        $admin = $this->adminWithTwoFactor();
        $jordan = $this->leadWithTwoFactor(['name' => 'Jordan']);

        $this->actingAs($admin)->post(route('people.two-factor.reset', $jordan), ['identity_checked' => '1'])
            ->assertSessionHas('status', 'Jordan\'s second step is reset. They\'ll set it up again next time they log in.');

        $jordan->refresh();
        $this->assertNull($jordan->two_factor_secret);
        $this->assertNull($jordan->two_factor_recovery_codes);
        $this->assertNull($jordan->two_factor_confirmed_at);
        Notification::assertSentTo($jordan, TwoFactorResetNotification::class);
        $entry = ChangeLogEntry::query()->about($jordan)->ofAction('two_factor.reset')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['hidden' => true], $entry->changes['two_factor_secret']);
        $this->assertSame(['hidden' => true], $entry->changes['two_factor_recovery_codes']);
        $this->assertNull($entry->details);
    }

    public function test_the_reset_signs_the_person_out_everywhere(): void
    {
        Notification::fake();
        $admin = $this->adminWithTwoFactor();
        $jordan = $this->leadWithTwoFactor();
        DB::table('sessions')->insert(['id' => 'jordan-phone', 'user_id' => $jordan->id, 'payload' => '', 'last_activity' => time()]);

        app(ResetTwoFactor::class)->handle($admin, $jordan);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $jordan->id)->count());
        $this->assertSame($admin->id, ChangeLogEntry::query()->about($jordan)->ofAction('user.signed_out_everywhere')->sole()->actor_id);
    }

    public function test_the_email_names_the_admin_and_says_what_happens_next(): void
    {
        Organization::factory()->create(['name' => 'Example Makers']);
        $admin = $this->adminWithTwoFactor(['name' => 'Morgan Rivera']);
        $jordan = $this->leadWithTwoFactor();

        $mail = (new TwoFactorResetNotification($admin))->toMail($jordan);

        $this->assertStringContainsString('Morgan R., an admin at Example Makers, reset the second step for your login. Next time you log in with your password, you\'ll set up your authenticator app again. If you didn\'t ask for this, contact a coordinator right away.', implode(' ', $mail->introLines));
        $this->assertStringNotContainsString('Rivera', implode(' ', $mail->introLines));
    }

    public function test_the_reset_needs_the_identity_check(): void
    {
        $jordan = $this->leadWithTwoFactor();

        $this->actingAs($this->adminWithTwoFactor())->post(route('people.two-factor.reset', $jordan))
            ->assertSessionHasErrors(['identity_checked' => 'Tick the box once you\'ve checked it\'s really them.']);

        $this->assertNotNull($jordan->fresh()->two_factor_secret);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('two_factor.reset')->count());
    }

    public function test_an_admin_cannot_reset_their_own_two_factor(): void
    {
        $admin = $this->adminWithTwoFactor();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Ask another admin to reset your own two-factor.');
        app(ResetTwoFactor::class)->handle($admin, $admin);
    }

    public function test_the_own_reset_attempt_through_the_page_shows_the_message(): void
    {
        $admin = $this->adminWithTwoFactor();

        $this->actingAs($admin)->post(route('people.two-factor.reset', $admin), ['identity_checked' => '1'])
            ->assertSessionHasErrors(['identity_checked' => 'Ask another admin to reset your own two-factor.']);

        $this->assertNotNull($admin->fresh()->two_factor_secret);
    }

    public function test_only_an_active_admin_can_run_the_action(): void
    {
        $this->expectException(AuthorizationException::class);
        app(ResetTwoFactor::class)->handle($this->coordinatorWithTwoFactor(), $this->leadWithTwoFactor());
    }

    public function test_a_turned_off_admin_cannot_run_the_action(): void
    {
        $admin = $this->adminWithTwoFactor(['deactivated_at' => now()]);

        $this->expectException(AuthorizationException::class);
        app(ResetTwoFactor::class)->handle($admin, $this->leadWithTwoFactor());
    }

    public function test_only_admins_can_reach_the_page_or_the_reset(): void
    {
        $jordan = $this->leadWithTwoFactor();
        $coordinator = $this->coordinatorWithTwoFactor();

        $this->actingAs($coordinator)->get(route('people.security', $jordan))->assertForbidden()->assertDontSee($jordan->email);
        $this->actingAs($coordinator)->post(route('people.two-factor.reset', $jordan), ['identity_checked' => '1'])->assertForbidden();
        $this->assertNotNull($jordan->fresh()->two_factor_secret);
    }

    public function test_the_page_shows_login_facts_and_the_confirmation_when_the_app_is_connected(): void
    {
        $jordan = $this->leadWithTwoFactor(['name' => 'Jordan']);

        $this->actingAs($this->adminWithTwoFactor())->get(route('people.security', $jordan))
            ->assertOk()
            ->assertSee($jordan->email)
            ->assertSee('Login and security')
            ->assertSee('Authenticator app')
            ->assertSee('Connected')
            ->assertSee('Reset Jordan\'s two-factor?', false)
            ->assertSee('I\'ve checked it\'s really Jordan asking, in person or by phone, not only by email or chat.', false)
            ->assertDontSee('Only admins see this button');
    }

    public function test_the_page_has_no_reset_when_no_app_is_connected(): void
    {
        $volunteer = User::factory()->create(['name' => 'Sam']);

        $this->actingAs($this->adminWithTwoFactor())->get(route('people.security', $volunteer))
            ->assertOk()
            ->assertSee('Not connected')
            ->assertDontSee('Reset Sam');
    }

    public function test_after_a_reset_the_next_login_goes_to_app_setup(): void
    {
        Notification::fake();
        $jordan = $this->leadWithTwoFactor();
        app(ResetTwoFactor::class)->handle($this->adminWithTwoFactor(), $jordan);

        $this->post('/login', ['email' => $jordan->email, 'password' => 'correct horse battery']);

        $this->get('/')->assertRedirect(route('account.security'));
    }

    public function test_a_reset_is_refused_when_no_app_is_connected(): void
    {
        Notification::fake();
        $sam = User::factory()->create(['name' => 'Sam Lee']);
        $sam->forceFill(['remember_token' => 'keep'])->save();

        $this->actingAs($this->adminWithTwoFactor())->post(route('people.two-factor.reset', $sam), ['identity_checked' => '1'])
            ->assertSessionHasErrors(['identity_checked' => 'Sam L. doesn\'t have an authenticator app connected.']);

        Notification::assertNothingSent();
        $this->assertSame(0, ChangeLogEntry::query()->about($sam)->count());
        $this->assertSame('keep', $sam->fresh()->remember_token);
    }

    public function test_non_admins_get_the_same_403_for_a_real_and_a_missing_person(): void
    {
        $coordinator = $this->actingAs($this->coordinatorWithTwoFactor());
        $real = $this->leadWithTwoFactor();

        $coordinator->get(route('people.security', $real))->assertForbidden();
        $coordinator->get(route('people.security', 999999))->assertForbidden();
        $coordinator->post(route('people.two-factor.reset', 999999), ['identity_checked' => '1'])->assertForbidden();
    }

    public function test_an_admin_gets_a_404_for_a_missing_person(): void
    {
        $this->actingAs($this->adminWithTwoFactor())->get(route('people.security', 999999))->assertNotFound();
    }
}
