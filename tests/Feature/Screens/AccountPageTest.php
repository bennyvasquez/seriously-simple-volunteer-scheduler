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

    public function test_a_volunteer_without_a_password_can_add_one(): void
    {
        $volunteer = User::factory()->withoutPassword()->create();

        $this->actingAs($volunteer)->get(route('account.show'))->assertOk()->assertSee('Add a password (optional)')->assertDontSee('Make new recovery codes');
    }

    public function test_a_volunteer_with_a_password_can_change_it(): void
    {
        $volunteer = User::factory()->create();

        $this->actingAs($volunteer)->get(route('account.show'))->assertSee('Change my password')->assertDontSee('Add a password')->assertDontSee('Make new recovery codes');
    }

    public function test_a_lead_sees_password_and_second_step(): void
    {
        $lead = $this->leadWithTwoFactor();

        $this->actingAs($lead)->get(route('account.show'))->assertSee('Change my password')->assertSee('Make new recovery codes')->assertDontSee('Add a password');
    }

    public function test_adding_a_first_password_returns_to_the_page_for_a_volunteer(): void
    {
        $volunteer = User::factory()->withoutPassword()->create();

        $this->actingAs($volunteer)->post(route('account.password.set'), ['password' => 'a long enough phrase', 'password_confirmation' => 'a long enough phrase'])
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

    public function test_the_email_button_is_disabled_until_the_change_screen_exists(): void
    {
        $this->actingAs(User::factory()->create())->get(route('account.show'))->assertSee('Change my email address');
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
}
