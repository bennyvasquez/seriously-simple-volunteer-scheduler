<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\IssueLoginLink;
use App\Actions\Accounts\SignOutEverywhere;
use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class SessionsAndDeactivationTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Turning someone off clears their future schedule, which reads the organization's date.
        Organization::factory()->create();
    }

    private function addSession(User $user, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
    }

    private function withRole(Role $role): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role);
        $this->enableTwoFactor($user);

        return $user;
    }

    public function test_signing_out_everywhere_ends_every_session(): void
    {
        $user = User::factory()->create(['remember_token' => 'old-token']);
        $other = User::factory()->create();
        $this->addSession($user, 'phone');
        $this->addSession($user, 'laptop');
        $this->addSession($other, 'someone-else');

        app(SignOutEverywhere::class)->handle($user);

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertSame(1, DB::table('sessions')->where('user_id', $other->id)->count());
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'user.signed_out_everywhere')->exists());
    }

    public function test_the_sign_out_everywhere_route_logs_you_out(): void
    {
        $this->actingAs(User::factory()->create())
            ->post(route('account.sign-out-everywhere'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_deactivating_someone_shuts_every_door(): void
    {
        $coordinator = $this->withRole(Role::Coordinator);
        $user = User::factory()->create(['email' => 'leaving@example.org', 'password' => Hash::make('correct horse battery'), 'remember_token' => 'old-token']);
        $this->addSession($user, 'phone');
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);

        app(DeactivateUser::class)->handle($coordinator, $user);

        $this->assertFalse($user->fresh()->isActive());
        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->assertSame(0, $user->loginLinks()->count());
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'user.deactivated')->where('actor_id', $coordinator->id)->exists());

        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))));
        $this->post('/login', ['email' => 'leaving@example.org', 'password' => 'correct horse battery']);
        $this->assertGuest();
    }

    public function test_a_volunteer_cannot_deactivate_anyone(): void
    {
        $this->expectException(AuthorizationException::class);

        app(DeactivateUser::class)->handle(User::factory()->create(), User::factory()->create());
    }

    public function test_nobody_can_deactivate_themselves(): void
    {
        $admin = $this->withRole(Role::Admin);

        $this->expectException(AuthorizationException::class);

        app(DeactivateUser::class)->handle($admin, $admin);
    }

    public function test_only_an_admin_can_deactivate_an_admin(): void
    {
        $admin = $this->withRole(Role::Admin);
        $coordinator = $this->withRole(Role::Coordinator);

        $this->expectException(AuthorizationException::class);

        app(DeactivateUser::class)->handle($coordinator, $admin);
    }

    public function test_an_open_session_ends_when_the_account_is_turned_off(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);
        $user->forceFill(['deactivated_at' => now()])->save();

        $this->get('/')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_a_session_for_someone_not_invited_yet_ends_with_nothing_that_says_the_account_exists(): void
    {
        $user = User::factory()->notInvited()->create();

        $this->actingAs($user)->get('/')
            ->assertRedirect(route('login'))
            ->assertSessionHasNoErrors()
            ->assertSessionMissing('status');
        $this->assertGuest();

        // The login page then reads the same as it does for anyone with no account.
        $this->get(route('login'))->assertOk()->assertDontSee('turned off')->assertDontSee('invited');

        $this->actingAs($user->fresh())->getJson(route('my-shifts'))->assertStatus(401)->assertExactJson(['message' => 'Unauthenticated.']);
        $this->assertGuest();
    }

    public function test_a_remember_me_cookie_stops_working_once_the_account_is_turned_off(): void
    {
        $coordinator = $this->withRole(Role::Coordinator);
        $user = User::factory()->create(['email' => 'leaving@example.org', 'password' => Hash::make('correct horse battery')]);
        $recallerName = Auth::guard('web')->getRecallerName();

        $recaller = $this->post('/login', ['email' => 'leaving@example.org', 'password' => 'correct horse battery', 'remember' => 'on'])
            ->assertRedirect('/')
            ->getCookie($recallerName)
            ->getValue();

        $this->startFreshBrowserWith($recallerName, $recaller);
        $this->get('/login')->assertRedirect('/');
        $this->assertAuthenticatedAs($user);

        app(DeactivateUser::class)->handle($coordinator, $user);

        $this->startFreshBrowserWith($recallerName, $recaller);
        $this->get('/login')->assertOk();
        $this->assertGuest();
    }

    /** No session and no logged-in guard left over from earlier requests, only the given cookie. */
    private function startFreshBrowserWith(string $cookie, string $value): void
    {
        $this->flushSession();
        $this->app['auth']->forgetGuards();
        $this->defaultCookies = [];
        $this->withCookie($cookie, $value);
    }

    public function test_deactivation_logs_when_it_happened(): void
    {
        $coordinator = $this->withRole(Role::Coordinator);
        $user = User::factory()->create();

        app(DeactivateUser::class)->handle($coordinator, $user);

        $entry = ChangeLogEntry::query()->where('action', 'user.deactivated')->sole();
        $this->assertNull($entry->changes['deactivated_at']['old']);
        $this->assertNotNull($entry->changes['deactivated_at']['new']);
        $this->assertSame(['hidden' => true], $entry->changes['remember_token']);
    }
}
