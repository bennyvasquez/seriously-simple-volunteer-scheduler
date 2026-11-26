<?php

namespace Tests\Feature\Accounts;

use App\Enums\LoginLinkPurpose;
use App\Enums\Role;
use App\Models\ChangeLogEntry;
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

    public function test_it_refuses_an_address_with_characters_other_than_plain_ones(): void
    {
        $this->setUpInstall(['--admin-email' => 'vïctim@example.org'])
            ->expectsOutputToContain('Type the address using only plain letters, numbers and symbols.')
            ->assertFailed();

        $this->assertFalse(Organization::query()->exists());
    }

    public function test_it_refuses_an_admin_name_with_control_characters(): void
    {
        $this->setUpInstall(['--admin-name' => "Alex\x07"])
            ->expectsOutputToContain("This name has characters we can't use.")
            ->assertFailed();

        $this->assertFalse(Organization::query()->exists());
        $this->assertSame(0, User::query()->count());
    }

    public function test_a_name_made_only_of_control_characters_gets_one_message(): void
    {
        $this->setUpInstall(['--admin-name' => "\x07"])
            ->expectsOutputToContain("This name has characters we can't use.")
            ->doesntExpectOutputToContain('Enter your name.')
            ->assertFailed();

        $this->assertSame(0, User::query()->count());
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
        $this->assertArrayNotHasKey('password', ChangeLogEntry::query()->where('action', 'user.created')->sole()->changes);
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

    public function test_setup_logs_the_organization_and_the_first_admin(): void
    {
        Notification::fake();
        $this->setUpInstall()->assertSuccessful();

        $organization = ChangeLogEntry::query()->where('action', 'organization.created')->sole();
        $this->assertTrue($organization->isBySystem());
        $this->assertSame('Example Makerspace', $organization->changes['name']['new']);
        $this->assertTrue(ChangeLogEntry::query()->where('action', 'user.created')->about(User::query()->sole())->exists());
    }
}
