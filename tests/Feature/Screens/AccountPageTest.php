<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\SignOutEverywhere;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class AccountPageTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private function leadWithTwoFactor(): User
    {
        $lead = User::factory()->create();
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, Area::factory()->create());
        $this->enableTwoFactor($lead);

        return $lead->fresh();
    }

    public function test_a_volunteer_without_a_password_can_add_one_right_after_a_link_sign_in(): void
    {
        $volunteer = User::factory()->withoutPassword()->create();

        $this->actingAs($volunteer)->withSession(['auth.link_login' => ['user' => $volunteer->id, 'at' => now()->subMinutes(14)->getTimestamp()]])
            ->get(route('account.show'))->assertOk()
            ->assertSee('Add a password (optional)')
            ->assertSee('action="'.route('account.password.set').'"', false)
            ->assertSee('Save password')
            ->assertDontSee('Log out to get a new link')
            ->assertDontSee('Make new recovery codes');
    }

    public function test_without_a_recent_link_sign_in_the_page_says_how_to_add_one_instead_of_showing_the_form(): void
    {
        $volunteer = User::factory()->withoutPassword()->create();
        $sessions = [
            'no link sign-in' => [],
            'a link sign-in over 15 minutes ago' => ['auth.link_login' => ['user' => $volunteer->id, 'at' => now()->subMinutes(16)->getTimestamp()]],
        ];

        foreach ($sessions as $case => $session) {
            $this->actingAs($volunteer)->withSession($session)
                ->get(route('account.show'))->assertOk()
                ->assertSee('Add a password (optional)')
                ->assertSee('To keep your account safe, we only add a password within 15 minutes of you signing in with an emailed link.')
                ->assertSee('Log out to get a new link')
                ->assertDontSee('action="'.route('account.password.set').'"', false)
                ->assertDontSee('Save password');
            $this->flushSession();
        }
    }

    public function test_the_page_has_its_own_heading_and_links_back_home(): void
    {
        $this->actingAs(User::factory()->create())->get(route('account.show'))
            ->assertSee('<h1>Login and security</h1>', false)
            ->assertSee('Back to home')
            ->assertDontSee('Back to Me');
    }

    public function test_a_volunteer_with_a_password_can_change_it(): void
    {
        $volunteer = User::factory()->create();

        $this->actingAs($volunteer)->get(route('account.show'))->assertSee('Change my password')->assertDontSee('Add a password')->assertDontSee('Make new recovery codes');
    }

    public function test_changing_a_password_or_email_warns_that_other_devices_are_logged_out(): void
    {
        $volunteer = User::factory()->create();

        $this->actingAs($volunteer)->get(route('account.show'))
            ->assertSee('Changing your password logs you out on your other phones and computers. You stay logged in here.');
        $this->actingAs($volunteer)->withSession(['auth.password_confirmed_at' => time()])->get(route('account.email.edit'))
            ->assertSee('Opening it logs you out on every phone and computer except the one you open it on.');
        auth()->logout();
        $this->get(route('password.reset', ['token' => 'not-a-real-token', 'email' => 'sam@example.org']))
            ->assertSee('any phone or computer that was logged in to your account is logged out', false);
    }

    public function test_a_lead_sees_password_and_second_step(): void
    {
        $lead = $this->leadWithTwoFactor();

        $this->actingAs($lead)->get(route('account.show'))->assertSee('Change my password')->assertSee('Make new recovery codes')->assertDontSee('Add a password');
    }

    public function test_adding_a_first_password_returns_to_the_page_for_a_volunteer(): void
    {
        $volunteer = User::factory()->withoutPassword()->create();

        $this->actingAs($volunteer)->withSession(['auth.link_login' => ['user' => $volunteer->id, 'at' => now()->getTimestamp()]])
            ->post(route('account.password.set'), ['password' => 'a long enough phrase', 'password_confirmation' => 'a long enough phrase'])
            ->assertRedirect(route('account.show'))->assertSessionHas('status', 'Your password is saved.');
    }

    public function test_the_page_shows_the_status_after_a_password_change(): void
    {
        $volunteer = User::factory()->create();

        $this->actingAs($volunteer)->withSession(['status' => 'password-updated'])->get(route('account.show'))->assertSee('Your password is changed.');
        $this->actingAs($volunteer)->withSession(['status' => 'Your password is saved.'])->get(route('account.show'))->assertSee('Your password is saved.');
    }

    public function test_new_recovery_codes_show_once_with_a_message(): void
    {
        $lead = $this->leadWithTwoFactor();

        $this->actingAs($lead)->withSession(['status' => 'recovery-codes-generated'])->get(route('account.show'))
            ->assertOk()->assertSee('Save your recovery codes')->assertSee("Here are your new recovery codes. The old ones don't work anymore.")->assertDontSee('The app is connected.');
    }

    public function test_regenerating_codes_asks_for_the_password_then_lands_on_the_codes(): void
    {
        $lead = $this->leadWithTwoFactor();

        $this->actingAs($lead)->post(route('two-factor.regenerate-recovery-codes'))->assertRedirect(route('password.confirm'));

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])->post(route('two-factor.regenerate-recovery-codes'))->assertRedirect();
    }

    public function test_the_email_button_links_to_the_change_screen(): void
    {
        $this->actingAs(User::factory()->create())->get(route('account.show'))
            ->assertSee('<a class="btn" href="'.route('account.email.edit').'">Change my email address</a>', false);
    }

    public function test_signing_out_everywhere_except_here_keeps_this_session(): void
    {
        $user = User::factory()->create();
        DB::table('sessions')->insert([
            ['id' => 'here', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'elsewhere', 'user_id' => $user->id, 'payload' => '', 'last_activity' => time()],
        ]);

        app(SignOutEverywhere::class)->handle($user, 'here');

        $this->assertSame(['here'], DB::table('sessions')->where('user_id', $user->id)->pluck('id')->all());
        $entry = ChangeLogEntry::query()->where('action', 'user.signed_out_everywhere')->latest('id')->first();
        $this->assertTrue($entry->details['kept_current_session']);
    }

    public function test_signing_out_everywhere_without_a_kept_session_records_that(): void
    {
        $user = User::factory()->create();

        app(SignOutEverywhere::class)->handle($user);

        $entry = ChangeLogEntry::query()->where('action', 'user.signed_out_everywhere')->latest('id')->first();
        $this->assertFalse($entry->details['kept_current_session']);
    }

    public function test_home_and_login_and_security_have_a_log_out_button(): void
    {
        $volunteer = User::factory()->create();
        $button = '<form method="POST" action="'.route('logout').'">';

        $this->actingAs($volunteer)->get(route('home'))->assertSee($button, false)->assertSee('Log out');
        $this->actingAs($volunteer)->get(route('account.show'))->assertSee($button, false)->assertSee('Log out');
    }

    public function test_setting_a_first_password_twice_goes_back_to_the_account_page(): void
    {
        $volunteer = User::factory()->create();
        $hash = $volunteer->password;

        $this->actingAs($volunteer)->post(route('account.password.set'), ['password' => 'a long enough phrase', 'password_confirmation' => 'a long enough phrase'])
            ->assertRedirect(route('account.show'))->assertSessionHas('status', 'You already have a password. You can change it below.');

        $this->assertSame($hash, $volunteer->fresh()->password);
    }

    public function test_new_recovery_codes_keep_the_login_and_security_header_and_are_not_cached(): void
    {
        $lead = $this->leadWithTwoFactor();

        $response = $this->actingAs($lead)->withSession(['status' => 'recovery-codes-generated'])->get(route('account.show'))
            ->assertSee('<div class="bar-name">Login and security</div>', false)->assertDontSee('Secure your account');

        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
    }

    public function test_the_copy_button_only_appears_when_the_clipboard_works(): void
    {
        $lead = $this->leadWithTwoFactor();

        $this->actingAs($lead)->withSession(['status' => 'recovery-codes-generated'])->get(route('account.show'))
            ->assertSee('<script src="'.asset('js/recovery-codes.js').'" defer></script>', false)
            ->assertSee('Couldn\'t copy. Select the codes and copy them instead.', false)
            ->assertDontSee('<script>', false);

        $this->assertStringContainsString('if (! navigator.clipboard)', file_get_contents(public_path('js/recovery-codes.js')));
    }
}
