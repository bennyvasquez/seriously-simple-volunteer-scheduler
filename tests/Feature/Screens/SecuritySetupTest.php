<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Fortify;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class SecuritySetupTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    private function area(): Area
    {
        return Area::factory()->create();
    }

    private function withRole(Role $role, ?Area $area = null, ?string $password = 'password'): User
    {
        $user = User::factory()->create(['password' => $password === null ? null : bcrypt($password)]);
        app(GrantRole::class)->handle(null, $user, $role, $area);

        return $user;
    }

    private function leadWithUnconfirmedTwoFactor(): User
    {
        $lead = $this->withRole(Role::AreaLead, $this->area());
        app(EnableTwoFactorAuthentication::class)($lead);

        return $lead->fresh();
    }

    private function currentCodeFor(User $user): string
    {
        return $this->currentCode(Fortify::currentEncrypter()->decrypt($user->two_factor_secret));
    }

    public function test_a_new_lead_sees_step_one(): void
    {
        $lead = $this->withRole(Role::AreaLead, $this->area(), password: null);

        $this->actingAs($lead)->get(route('account.security'))->assertOk()->assertSee('Step 1 of 2')->assertSee('As an area lead');
    }

    public function test_the_step_one_sentence_names_the_highest_role(): void
    {
        $coordinator = $this->withRole(Role::Coordinator, password: null);
        $this->actingAs($coordinator)->get(route('account.security'))->assertSee('As a coordinator')->assertDontSee('As an area lead');

        $admin = $this->withRole(Role::Admin, password: null);
        app(GrantRole::class)->handle(null, $admin, Role::Coordinator);
        app(GrantRole::class)->handle(null, $admin, Role::AreaLead, $this->area());
        $this->actingAs($admin)->get(route('account.security'))->assertSee('As an admin');
    }

    public function test_saving_the_first_password_goes_straight_to_the_qr_code(): void
    {
        $lead = $this->withRole(Role::AreaLead, $this->area(), password: null);

        $this->actingAs($lead)->followingRedirects()
            ->post(route('account.password.set'), ['password' => 'correct horse battery', 'password_confirmation' => 'correct horse battery'])
            ->assertSee('Step 2 of 2')->assertSee('<svg', false)->assertDontSee('Confirm it\'s you');

        $this->assertNotNull($lead->fresh()->two_factor_secret);
    }

    public function test_the_setup_key_is_shown_in_plain_text(): void
    {
        $lead = $this->leadWithUnconfirmedTwoFactor();
        $key = Fortify::currentEncrypter()->decrypt($lead->two_factor_secret);

        $this->actingAs($lead)->get(route('account.security'))->assertSee($key);
    }

    public function test_confirming_the_app_shows_the_recovery_codes_once(): void
    {
        $lead = $this->leadWithUnconfirmedTwoFactor();

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])->from(route('account.security'))->followingRedirects()
            ->post(route('two-factor.confirm'), ['code' => $this->currentCodeFor($lead)])
            ->assertSee('Save your recovery codes')->assertSee($lead->fresh()->recoveryCodes()[0]);

        $this->actingAs($lead->fresh())->get(route('account.security'))->assertRedirect(route('home'));
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'two_factor.enabled')->exists());
    }

    public function test_the_download_link_uses_the_organization_name(): void
    {
        Organization::factory()->create(['name' => 'Example Makerspace']);
        $lead = $this->leadWithUnconfirmedTwoFactor();

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])->from(route('account.security'))->followingRedirects()
            ->post(route('two-factor.confirm'), ['code' => $this->currentCodeFor($lead)])
            ->assertSee('download="example-makerspace-recovery-codes.txt"', false)
            ->assertSee('data:text/plain;charset=utf-8,', false)
            ->assertSee('Copy codes');
    }

    public function test_a_wrong_code_stays_on_step_two(): void
    {
        $lead = $this->leadWithUnconfirmedTwoFactor();

        $this->actingAs($lead)->withSession(['auth.password_confirmed_at' => time()])->from(route('account.security'))->followingRedirects()
            ->post(route('two-factor.confirm'), ['code' => '000000'])
            ->assertSee('Step 2 of 2')->assertDontSee('Save your recovery codes');
    }

    public function test_a_lead_with_a_password_but_no_app_gets_a_start_button(): void
    {
        $lead = $this->withRole(Role::AreaLead, $this->area());

        $this->actingAs($lead)->get(route('account.security'))->assertSee('Set up the app');
    }
}
