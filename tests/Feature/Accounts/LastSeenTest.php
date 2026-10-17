<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\IssueLoginLink;
use App\Enums\LoginLinkPurpose;
use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class LastSeenTest extends TestCase
{
    use RefreshDatabase;

    public function test_logging_in_records_last_login_and_last_seen(): void
    {
        $user = User::factory()->create(['email' => 'volunteer@example.org', 'password' => Hash::make('correct horse battery')]);

        $this->post('/login', ['email' => 'volunteer@example.org', 'password' => 'correct horse battery']);

        $this->assertNotNull($user->fresh()->last_login_at);
        $this->assertNotNull($user->fresh()->last_seen_at);
    }

    public function test_a_link_login_records_last_login(): void
    {
        $user = User::factory()->withoutPassword()->create();
        $url = app(IssueLoginLink::class)->handle($user, LoginLinkPurpose::SignIn);

        $this->post(route('login-link.consume', basename(parse_url($url, PHP_URL_PATH))));

        $this->assertNotNull($user->fresh()->last_login_at);
    }

    public function test_last_seen_updates_at_most_once_an_hour(): void
    {
        $user = User::factory()->create(['last_seen_at' => now()->subMinutes(30)]);
        $before = $user->last_seen_at;

        $this->actingAs($user)->get('/');
        $this->assertEquals($before, $user->fresh()->last_seen_at);

        $this->travel(31)->minutes();
        $this->actingAs($user)->get('/');
        $this->assertTrue($user->fresh()->last_seen_at->greaterThan($before));
    }

    public function test_activity_is_not_a_change_log_entry(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/');

        $this->assertSame(0, ChangeLogEntry::query()->count());
    }

    public function test_a_deactivated_person_who_is_logged_out_is_not_recorded(): void
    {
        $user = User::factory()->create(['deactivated_at' => now()]);

        $this->actingAs($user)->get('/');

        $this->assertNull($user->fresh()->last_seen_at);
    }
}
