<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
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
        $this->actingAs($this->coordinator())
            ->post(route('account.password.set'), ['password' => 'a long enough password', 'password_confirmation' => 'a long enough password'])
            ->assertForbidden();
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

    public function test_turning_two_factor_off_is_logged_and_locks_the_app_again(): void
    {
        $coordinator = $this->coordinator();
        $this->enableTwoFactor($coordinator);
        $this->actingAs($coordinator)->withSession(['auth.password_confirmed_at' => time()]);

        $this->delete(route('two-factor.disable'));

        $this->get('/')->assertRedirect(route('account.security'));
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'two_factor.disabled')->exists());
    }
}
