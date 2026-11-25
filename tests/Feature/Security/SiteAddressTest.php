<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\CoverRequestNotification;
use App\Notifications\InvitationNotification;
use App\Notifications\LoginLinkNotification;
use App\Providers\AppServiceProvider;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class SiteAddressTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const SITE = 'https://ssvs.example.org';

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        Organization::factory()->create(['timezone' => 'America/Detroit']);

        // The site's address is read when the app starts, so the test's setting is applied the same way.
        config(['app.url' => self::SITE]);
        $this->app->getProvider(AppServiceProvider::class)->useSiteAddress();
    }

    /** The same page, asked for at another address. */
    private function elsewhere(string $url): string
    {
        return 'http://attacker.example'.parse_url($url, PHP_URL_PATH);
    }

    public function test_a_login_link_requested_with_another_host_points_at_the_site(): void
    {
        $user = User::factory()->create(['email' => 'volunteer@example.org']);

        $this->post($this->elsewhere(route('login-link.request')), ['email' => 'volunteer@example.org']);

        Notification::assertSentTo($user, LoginLinkNotification::class, function (LoginLinkNotification $notification) {
            $this->assertStringStartsWith(self::SITE.'/login-link/', $notification->url);
            $this->assertStringNotContainsString('attacker.example', $notification->url);

            return true;
        });
    }

    public function test_a_password_reset_requested_with_another_host_points_at_the_site(): void
    {
        $user = User::factory()->create(['email' => 'volunteer@example.org']);

        $this->post($this->elsewhere(route('password.email')), ['email' => 'volunteer@example.org']);

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user) {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith(self::SITE.'/', $url);
            $this->assertStringNotContainsString('attacker.example', $url);

            return true;
        });
    }

    public function test_an_invitation_sent_with_another_host_points_at_the_site(): void
    {
        $coordinator = User::factory()->create();
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $this->enableTwoFactor($coordinator);

        $this->actingAs($coordinator)
            ->post($this->elsewhere(route('people.invite.store')), ['name' => 'Robin Example', 'email' => 'robin@example.org', 'starts_as' => 'volunteer']);

        $robin = User::query()->where('email', 'robin@example.org')->firstOrFail();
        Notification::assertSentTo($robin, InvitationNotification::class, function (InvitationNotification $notification) {
            $this->assertStringStartsWith(self::SITE.'/login-link/', $notification->url);
            $this->assertStringNotContainsString('attacker.example', $notification->url);

            return true;
        });
    }

    public function test_a_cover_request_sent_with_another_host_points_at_the_site(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        $area = Area::factory()->create(['name' => 'Front desk']);
        $coordinator = User::factory()->create();
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $robin = User::factory()->cleared()->create(['name' => 'Robin Example']);
        $shift = app(CreateOneTimeShift::class)->handle($coordinator, $area, '2026-10-22', '18:30', '21:30', 1, 1, null);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $sam->id, 'type' => AssignmentType::Regular]);

        $this->actingAs($sam)->post($this->elsewhere(route('shifts.release', $shift)), ['scope' => 'one']);

        Notification::assertSentTo($robin, CoverRequestNotification::class, function (CoverRequestNotification $notification) {
            $this->assertNotEmpty($notification->shifts);

            foreach ($notification->shifts as $shift) {
                $this->assertStringStartsWith(self::SITE.'/', $shift['url']);
                $this->assertStringNotContainsString('attacker.example', $shift['url']);
            }

            return true;
        });
    }
}
