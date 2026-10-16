<?php

namespace Tests\Feature\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\InvitationNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SetupCommandTest extends TestCase
{
    use RefreshDatabase;

    private function setUpInstall(array $overrides = [])
    {
        return $this->artisan('ssvs:setup', [
            '--organization' => 'Example Makerspace',
            '--timezone' => 'America/Detroit',
            '--admin-name' => 'Alex',
            '--admin-email' => 'Admin@Example.org',
            ...$overrides,
        ]);
    }

    public function test_it_creates_the_organization_and_first_admin(): void
    {
        Notification::fake();

        $this->setUpInstall()
            ->expectsOutputToContain('/login-link/')
            ->assertSuccessful();

        $organization = Organization::current();
        $this->assertSame('Example Makerspace', $organization->name);
        $this->assertSame('America/Detroit', $organization->timezone);

        $admin = User::query()->where('email', 'admin@example.org')->firstOrFail();
        $this->assertTrue($admin->hasRole(Role::Admin));
        $this->assertNull($admin->password);
        $this->assertSame(1, $admin->loginLinks()->where('purpose', LoginLinkPurpose::Invitation->value)->count());
        Notification::assertSentTo($admin, InvitationNotification::class);
    }

    public function test_it_refuses_to_run_twice(): void
    {
        Notification::fake();
        $this->setUpInstall()->assertSuccessful();

        $this->setUpInstall(['--admin-email' => 'other@example.org'])
            ->expectsOutputToContain('already set up')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_an_invalid_time_zone_creates_nothing(): void
    {
        $this->setUpInstall(['--timezone' => 'Michigan'])
            ->expectsOutputToContain('time zone')
            ->assertFailed();

        $this->assertSame(0, Organization::query()->count());
        $this->assertSame(0, User::query()->count());
    }

    public function test_an_invalid_email_creates_nothing(): void
    {
        $this->setUpInstall(['--admin-email' => 'not an address'])->assertFailed();

        $this->assertSame(0, Organization::query()->count());
    }

    public function test_the_database_refuses_a_second_organization(): void
    {
        Organization::factory()->create();

        $this->expectException(UniqueConstraintViolationException::class);

        DB::table('organizations')->insert(['name' => 'Second', 'timezone' => 'UTC', 'weeks_ahead' => 8, 'created_at' => now(), 'updated_at' => now()]);
    }
}
