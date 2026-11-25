<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\ImportPeople;
use App\Enums\Role;
use App\Import\HelloClubExport;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\LoginLinkNotification;
use App\Support\EmailAddress;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class EmailAddressSpellingTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const PASSWORD = 'correct horse battery';

    private User $victim;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        Organization::factory()->create();
        $this->victim = User::factory()->create(['email' => 'victim@example.org', 'password' => Hash::make(self::PASSWORD)]);
    }

    public function test_an_accented_look_alike_gets_no_login_link(): void
    {
        $this->post(route('login-link.request'), ['email' => 'vïctim@example.org'])
            ->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);

        Notification::assertNothingSent();
    }

    public function test_a_zero_width_look_alike_gets_no_login_link(): void
    {
        $this->post(route('login-link.request'), ['email' => "vi\u{200B}ctim@example.org"])
            ->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);

        Notification::assertNothingSent();
    }

    public function test_a_look_alike_gets_no_password_reset(): void
    {
        $this->post(route('password.email'), ['email' => 'vïctim@example.org'])
            ->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);

        Notification::assertNothingSent();
    }

    public function test_a_look_alike_password_login_fails(): void
    {
        $this->post(route('login.store'), ['email' => 'vïctim@example.org', 'password' => self::PASSWORD])
            ->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);

        $this->assertGuest();
    }

    public function test_a_mixed_case_address_still_works(): void
    {
        $this->post(route('login-link.request'), ['email' => 'Victim@Example.org']);
        Notification::assertSentTo($this->victim, LoginLinkNotification::class);

        $this->post(route('password.email'), ['email' => ' Victim@Example.org ']);
        Notification::assertSentTo($this->victim, ResetPassword::class);

        $this->post(route('login.store'), ['email' => 'Victim@Example.org', 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($this->victim);
    }

    public function test_the_refusal_is_the_same_for_an_unknown_look_alike(): void
    {
        $this->post(route('login-link.request'), ['email' => 'vïctim@example.org'])->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);
        $real = session('errors')->get('email');

        $this->post(route('login-link.request'), ['email' => 'nöbody@example.org'])->assertSessionHasErrors(['email' => EmailAddress::REFUSAL]);

        $this->assertSame($real, session('errors')->get('email'));
    }

    /** Spellings of one address share a sign-in limit, so a look-alike can't be a way round it. */
    public function test_spellings_of_one_address_share_the_login_link_limit(): void
    {
        foreach (['victim@example.org', 'Victim@example.org', ' VICTIM@example.org', 'victim@Example.org'] as $spelling) {
            $this->post(route('login-link.request'), ['email' => $spelling]);
        }

        $this->post(route('login-link.request'), ['email' => 'vICTIM@example.org'])->assertStatus(429);
    }

    public function test_the_change_log_email_filter_is_untouched(): void
    {
        $coordinator = User::factory()->cleared()->create();
        $coordinator->roles()->create(['role' => Role::Coordinator->value]);
        $this->enableTwoFactor($coordinator);

        // The change log's email field is a kind of change, not an address, and isn't lowercased, trimmed or refused.
        $this->actingAs($coordinator)->get(route('change-log.index', ['email' => 'Login_Link']))
            ->assertOk()
            ->assertSessionHasNoErrors();
    }

    public function test_import_skips_a_non_ascii_address(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, "Full name,Email,Profile ID\nVictor Example,vïctim@example.org,v1\n");

        try {
            $preview = app(ImportPeople::class)->preview(HelloClubExport::read($path), null);
        } finally {
            unlink($path);
        }

        $this->assertSame(['The email address has characters other than plain letters, numbers and symbols'], $preview->problems->pluck('reason')->all());
        $this->assertCount(0, $preview->add);
    }
}
