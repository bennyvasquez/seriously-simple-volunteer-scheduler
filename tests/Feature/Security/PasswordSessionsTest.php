<?php

namespace Tests\Feature\Security;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

/** Changing or resetting a password signs the account out on every other device (spec section 13, "Sessions"). */
class PasswordSessionsTest extends TestCase
{
    use RefreshDatabase;

    private function sessionRow(string $id, User $user): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'test', 'payload' => '', 'last_activity' => time()]);
    }

    public function test_changing_your_password_signs_out_your_other_devices(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct horse battery'), 'remember_token' => 'old-token']);
        $this->sessionRow('this-device', $user);
        $this->sessionRow('other-device', $user);

        $this->withSession([])->actingAs($user);
        $this->app['session.store']->setId('this-device');

        $this->put(route('user-password.update'), ['current_password' => 'correct horse battery', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'])
            ->assertSessionHasNoErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'other-device']);
        // The device in use is kept (the entry below says so), then moved to a new session id, so the old row is gone too.
        $this->assertDatabaseMissing('sessions', ['id' => 'this-device']);
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $this->assertNotSame('this-device', $this->app['session.store']->getId());
        $entry = ChangeLogEntry::query()->where('action', 'user.signed_out_everywhere')->sole();
        $this->assertSame($user->id, $entry->subject_id);
        $this->assertTrue($entry->details['kept_current_session']);
        $this->assertAuthenticatedAs($user);
        $this->get(route('account.show'))->assertOk();
    }

    public function test_a_remembered_device_stays_remembered_and_other_cookies_stop_working(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct horse battery'), 'remember_token' => 'old-token']);
        $name = Auth::guard('web')->getRecallerName();
        // The test client encrypts the cookie value itself, so this is the plain "id|token|hash" the browser would hold.
        $recaller = fn (string $token, string $passwordHash) => $user->id.'|'.$token.'|'.Auth::guard('web')->hashPasswordForCookie($passwordHash);
        $oldCookie = $recaller('old-token', $user->password);

        $response = $this->actingAs($user)->withCookie($name, $oldCookie)
            ->put(route('user-password.update'), ['current_password' => 'correct horse battery', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'])
            ->assertSessionHasNoErrors();

        $response->assertCookie($name);
        $fresh = $user->fresh();
        $this->assertNotSame('old-token', $fresh->remember_token);
        $this->assertNotSame($oldCookie, $response->getCookie($name, false)?->getValue());
        $this->assertNotSame($oldCookie, $recaller($fresh->remember_token, $fresh->password));

        // The old cookie, from another device, no longer signs anyone in.
        Auth::forgetGuards();
        $this->flushSession();
        $this->app['auth']->shouldUse('web');
        $this->withCookie($name, $oldCookie)->get(route('account.show'))->assertRedirect(route('login'));
    }

    public function test_another_persons_leftover_remember_cookie_is_not_turned_into_one_for_the_person_changing_their_password(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct horse battery'), 'remember_token' => 'old-token']);
        $other = User::factory()->create(['name' => 'Dana Example1', 'email' => 'dana@example.org', 'remember_token' => 'other-token']);
        $name = Auth::guard('web')->getRecallerName();
        $othersCookie = $other->id.'|other-token|'.Auth::guard('web')->hashPasswordForCookie($other->password);

        $this->actingAs($user)->withCookie($name, $othersCookie)
            ->put(route('user-password.update'), ['current_password' => 'correct horse battery', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'])
            ->assertSessionHasNoErrors()
            ->assertCookieMissing($name);
    }

    public function test_a_device_that_was_not_remembered_is_not_given_a_remember_cookie(): void
    {
        $user = User::factory()->create(['password' => Hash::make('correct horse battery')]);

        $this->actingAs($user)
            ->put(route('user-password.update'), ['current_password' => 'correct horse battery', 'password' => 'a brand new password', 'password_confirmation' => 'a brand new password'])
            ->assertCookieMissing(Auth::guard('web')->getRecallerName());
    }

    public function test_a_reset_signs_out_every_device(): void
    {
        Notification::fake();
        $user = User::factory()->create(['email' => 'volunteer@example.org', 'remember_token' => 'old-token']);
        $this->sessionRow('device-one', $user);
        $this->sessionRow('device-two', $user);

        $this->post(route('password.update'), [
            'token' => Password::broker()->createToken($user),
            'email' => 'volunteer@example.org',
            'password' => 'a brand new password',
            'password_confirmation' => 'a brand new password',
        ])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('sessions')->where('user_id', $user->id)->count());
        $this->assertNotSame('old-token', $user->fresh()->remember_token);
        $entry = ChangeLogEntry::query()->where('action', 'user.signed_out_everywhere')->sole();
        $this->assertSame($user->id, $entry->subject_id);
        $this->assertFalse($entry->details['kept_current_session']);
    }
}
