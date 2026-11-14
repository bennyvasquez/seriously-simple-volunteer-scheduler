<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\IssueLoginLink;
use App\Actions\Accounts\ResetTwoFactor;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Http\Middleware\EnsureAccountIsSecure;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class SecurityRequirementsTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private function coordinator(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        app(GrantRole::class)->handle(null, $user, Role::Coordinator);

        return $user;
    }

    public function test_a_coordinator_without_two_factor_is_sent_to_set_it_up(): void
    {
        $this->actingAs($this->coordinator())
            ->get('/')
            ->assertRedirect(route('account.security'));
    }

    public function test_json_requests_get_a_clear_refusal(): void
    {
        $this->actingAs($this->coordinator())
            ->getJson('/')
            ->assertForbidden();
    }

    public function test_the_setup_screen_itself_is_reachable(): void
    {
        $this->actingAs($this->coordinator())
            ->get(route('account.security'))
            ->assertOk();
    }

    public function test_a_coordinator_with_a_password_and_two_factor_gets_in(): void
    {
        $coordinator = $this->coordinator();
        $this->enableTwoFactor($coordinator);

        $this->actingAs($coordinator)->get('/')->assertOk();
    }

    public function test_two_factor_without_a_password_is_not_enough(): void
    {
        $coordinator = $this->coordinator(['password' => null]);
        $this->enableTwoFactor($coordinator);

        $this->actingAs($coordinator)->get('/')->assertRedirect(route('account.security'));
    }

    public function test_volunteers_are_not_held_back(): void
    {
        $this->actingAs(User::factory()->create())->get('/')->assertOk();
    }

    public function test_an_area_lead_without_two_factor_is_sent_to_set_it_up(): void
    {
        $lead = User::factory()->create();
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, Area::factory()->create());

        $this->actingAs($lead)->get('/')->assertRedirect(route('account.security'));
    }

    public function test_someone_without_a_password_can_set_one(): void
    {
        $coordinator = $this->coordinator(['password' => null]);

        $this->actingAs($coordinator)
            ->post(route('account.password.set'), ['password' => 'a long enough password', 'password_confirmation' => 'a long enough password'])
            ->assertRedirect(route('account.security'));

        $this->assertTrue(Hash::check('a long enough password', $coordinator->fresh()->password));
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'user.password_set')->exists());
    }

    public function test_a_short_password_is_refused(): void
    {
        $coordinator = $this->coordinator(['password' => null]);

        $this->actingAs($coordinator)
            ->post(route('account.password.set'), ['password' => 'short', 'password_confirmation' => 'short'])
            ->assertSessionHasErrors('password');

        $this->assertNull($coordinator->fresh()->password);
    }

    public function test_setting_a_first_password_cannot_replace_an_existing_one(): void
    {
        $coordinator = $this->coordinator();
        $hash = $coordinator->password;

        // A coordinator still in setup goes straight back to the setup screen, so the message isn't lost on a second redirect (issue #14).
        $this->actingAs($coordinator)
            ->post(route('account.password.set'), ['password' => 'a long enough password', 'password_confirmation' => 'a long enough password'])
            ->assertRedirect(route('account.security'));

        $this->assertSame($hash, $coordinator->fresh()->password);
        $this->get(route('account.security'))->assertSee('You already have a password, so the next step is the authenticator app.');
    }

    public function test_a_volunteer_who_already_has_a_password_goes_to_login_and_security(): void
    {
        $volunteer = User::factory()->create();

        $this->actingAs($volunteer)
            ->post(route('account.password.set'), ['password' => 'a long enough password', 'password_confirmation' => 'a long enough password'])
            ->assertRedirect(route('account.show'));

        $this->get(route('account.show'))->assertSee('You already have a password. You can change it below.');
    }

    public function test_the_setup_screen_doesnt_show_fortify_keywords(): void
    {
        $this->actingAs($this->coordinator())->withSession(['status' => 'two-factor-authentication-enabled'])
            ->get(route('account.security'))->assertDontSee('two-factor-authentication-enabled');
    }

    public function test_a_coordinator_can_turn_on_two_factor_and_then_get_in(): void
    {
        $coordinator = $this->coordinator();
        $this->actingAs($coordinator)->withSession(['auth.password_confirmed_at' => time()]);

        $this->post(route('two-factor.enable'))->assertRedirect();
        $secret = Fortify::currentEncrypter()->decrypt($coordinator->fresh()->two_factor_secret);
        $this->post(route('two-factor.confirm'), ['code' => $this->currentCode($secret)])->assertRedirect();

        $this->get('/')->assertOk();
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'two_factor.enabled')->exists());
    }

    public function test_after_a_two_factor_reset_a_password_login_goes_straight_to_the_app_setup(): void
    {
        $admin = User::factory()->create();
        app(GrantRole::class)->handle(null, $admin, Role::Admin);
        $coordinator = $this->coordinator(['email' => 'coordinator@example.org', 'password' => Hash::make('correct horse battery')]);
        $this->enableTwoFactor($coordinator);
        app(ResetTwoFactor::class)->handle($admin, $coordinator->fresh());

        $this->post('/login', ['email' => 'coordinator@example.org', 'password' => 'correct horse battery'])->assertRedirect();

        // They just typed their password, so "Set up the app" doesn't ask for it again (issue #7).
        $this->from(route('account.security'))->post(route('two-factor.enable'))->assertRedirect(route('account.security'));
        $this->assertNotNull($coordinator->fresh()->two_factor_secret);
    }

    public function test_after_a_link_login_setting_up_the_app_still_asks_for_the_password(): void
    {
        $coordinator = $this->coordinator(['password' => Hash::make('correct horse battery')]);
        $token = basename(parse_url(app(IssueLoginLink::class)->handle($coordinator, LoginLinkPurpose::SignIn), PHP_URL_PATH));

        $this->post(route('login-link.consume', $token))->assertRedirect();
        $this->assertAuthenticatedAs($coordinator);

        $this->from(route('account.security'))->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
    }

    public function test_a_link_login_after_someone_elses_abandoned_password_login_still_asks_for_the_password(): void
    {
        // Someone with two-factor typed their password in this browser and walked away at the code screen, leaving the pending password login in the session.
        $other = User::factory()->create();
        $coordinator = $this->coordinator(['password' => Hash::make('correct horse battery')]);
        $token = basename(parse_url(app(IssueLoginLink::class)->handle($coordinator, LoginLinkPurpose::SignIn), PHP_URL_PATH));

        $this->withSession(['login.id' => $other->id, 'login.via' => 'password'])->post(route('login-link.consume', $token))->assertRedirect();
        $this->assertAuthenticatedAs($coordinator);

        // The coordinator never typed a password, so setting up the app still asks for it.
        $this->from(route('account.security'))->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
        $this->assertNull(session('login.id'));
    }

    public function test_a_password_login_by_someone_who_doesnt_need_setup_doesnt_count_as_confirming(): void
    {
        User::factory()->create(['email' => 'volunteer@example.org', 'password' => Hash::make('correct horse battery')]);

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => 'correct horse battery'])->assertRedirect();

        $this->get(route('account.email.edit'))->assertRedirect(route('password.confirm'));
        $this->assertNull(session('login.via'));
    }

    public function test_turning_two_factor_off_is_logged_and_locks_the_app_again(): void
    {
        $coordinator = $this->coordinator();
        $this->enableTwoFactor($coordinator);
        $this->actingAs($coordinator)->withSession(['auth.password_confirmed_at' => time()]);

        $this->delete(route('two-factor.disable'));

        $this->get('/')->assertRedirect(route('account.security'));
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'two_factor.disabled')->exists());
    }

    public function test_every_route_allowed_during_setup_exists(): void
    {
        foreach (EnsureAccountIsSecure::ALLOWED_DURING_SETUP as $name) {
            $this->assertTrue(Route::has($name), "The route {$name} that people need during security setup doesn't exist. Fortify may have renamed it.");
        }
    }

    public function test_regenerating_recovery_codes_is_logged(): void
    {
        $coordinator = $this->coordinator();
        $this->enableTwoFactor($coordinator);

        $this->actingAs($coordinator)->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.regenerate-recovery-codes'));

        $entry = ChangeLogEntry::query()->where('action', 'two_factor.recovery_codes_regenerated')->sole();
        $this->assertSame($coordinator->id, $entry->affected_user_id);
        $this->assertStringNotContainsString('recovery-code', json_encode($entry->getAttributes()));
    }
}
