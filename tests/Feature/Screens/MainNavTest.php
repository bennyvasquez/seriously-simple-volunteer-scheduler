<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class MainNavTest extends TestCase
{
    use InteractsWithTwoFactor;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
    }

    public function test_the_menu_is_on_home_schedule_and_me_pages(): void
    {
        $this->actingAs(User::factory()->cleared()->create());

        foreach (['home', 'calendar.week', 'account.show'] as $route) {
            $this->get(route($route))
                ->assertOk()
                ->assertSee('<nav aria-label="Main"', false)
                ->assertSee('href="'.route('home').'"', false)
                ->assertSee('href="'.route('calendar.week').'"', false)
                ->assertSee('href="'.route('account.show').'"', false);
        }
    }

    public function test_the_current_page_is_marked(): void
    {
        $this->actingAs(User::factory()->cleared()->create());

        $this->assertMainNavCurrent(route('home'), 'Home');
        $this->assertMainNavCurrent(route('calendar.week'), 'Schedule');
        $this->assertMainNavCurrent(route('calendar.month'), 'Schedule');
        $this->assertMainNavCurrent(route('account.show'), 'Me');
        $this->assertMainNavCurrent(route('account.emails'), 'Me');
    }

    private function assertMainNavCurrent(string $url, string $label): void
    {
        $html = $this->get($url)->assertOk()->getContent();
        preg_match('/<nav aria-label="Main".*?<\/nav>/s', $html, $nav);
        preg_match_all('/aria-current="page"[^>]*>([^<]+)</', $nav[0], $current);

        $this->assertSame([$label], $current[1], $url);
    }

    public function test_guests_dont_see_the_menu(): void
    {
        $this->get(route('login'))->assertOk()->assertDontSee('aria-label="Main"', false);
    }

    public function test_a_coordinator_who_hasnt_finished_two_factor_sees_no_menu(): void
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, Role::Coordinator, null);

        $this->actingAs($user)->get(route('account.security'))->assertOk()->assertDontSee('aria-label="Main"', false);
    }

    public function test_a_coordinator_with_no_password_sees_no_menu_where_theyre_sent(): void
    {
        $user = User::factory()->withoutPassword()->create();
        app(GrantRole::class)->handle(null, $user, Role::Coordinator, null);
        $this->enableTwoFactor($user);

        $this->actingAs($user)->get(route('home'))->assertRedirect(route('account.security'));
        $this->get(route('account.security'))->assertOk()->assertDontSee('aria-label="Main"', false);
    }

    public function test_has_finished_security_setup_covers_each_case(): void
    {
        $this->assertTrue(User::factory()->withoutPassword()->create()->hasFinishedSecuritySetup());

        $noTwoFactor = User::factory()->create();
        app(GrantRole::class)->handle(null, $noTwoFactor, Role::Coordinator, null);
        $this->assertFalse($noTwoFactor->hasFinishedSecuritySetup());

        $noPassword = User::factory()->withoutPassword()->create();
        app(GrantRole::class)->handle(null, $noPassword, Role::Coordinator, null);
        $this->enableTwoFactor($noPassword);
        $this->assertFalse($noPassword->hasFinishedSecuritySetup());

        $done = User::factory()->create();
        app(GrantRole::class)->handle(null, $done, Role::Coordinator, null);
        $this->enableTwoFactor($done);
        $this->assertTrue($done->hasFinishedSecuritySetup());
    }

    public function test_home_has_no_calendar_button(): void
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, Role::Coordinator, null);
        $this->enableTwoFactor($user);
        $this->actingAs($user);

        $this->get(route('home'))->assertOk()->assertDontSee('>Calendar<', false);
    }

    public function test_the_calendar_bar_has_no_me_button(): void
    {
        $this->actingAs(User::factory()->cleared()->create());

        $this->get(route('calendar.week'))->assertOk()->assertDontSee('class="btn">Me<', false);
    }

    public function test_the_shadow_home_points_to_the_schedule(): void
    {
        $this->actingAs(User::factory()->create(['cleared_at' => null]));

        $this->get(route('home'))->assertOk()->assertSee('You can see the full schedule under Schedule.');
    }
}
