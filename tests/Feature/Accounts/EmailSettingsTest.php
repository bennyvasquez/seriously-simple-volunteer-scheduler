<?php

namespace Tests\Feature\Accounts;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\UpdateEmailSettings;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\ChangeLog\Describe;
use App\Cover\CoverEligibility;
use App\Enums\Role;
use App\Http\Controllers\ChangeLogController;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_emails_page_shows_the_setting(): void
    {
        $sam = User::factory()->create()->fresh();

        $this->actingAs($sam)->get(route('account.emails'))->assertOk()
            ->assertSee("Email me when someone can't make a shift I could take", false)
            ->assertSee('Usually one or two emails each time a shift needs covering.')
            ->assertSee('Always sent')
            ->assertSee('name="cover_request_emails" value="1" checked', false)
            ->assertSee('href="'.route('home').'"', false);

        $sam->forceFill(['cover_request_emails' => false])->save();
        $this->actingAs($sam->fresh())->get(route('account.emails'))->assertDontSee('value="1" checked', false);
    }

    public function test_a_guest_is_sent_to_log_in_and_lands_back_on_the_emails_page(): void
    {
        $this->get(route('account.emails'))->assertRedirect(route('login'));
    }

    public function test_turning_cover_requests_off_and_on(): void
    {
        Organization::factory()->create(['timezone' => 'UTC']);
        $sam = User::factory()->create(['name' => 'Sam Example'])->fresh();
        $coordinator = User::factory()->create();
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $shift = app(CreateOneTimeShift::class)->handle($coordinator, Area::factory()->create(), now()->addDays(3)->toDateString(), '18:00', '20:00', 1, 0, null);
        $this->assertTrue(CoverEligibility::recipients($shift)->contains('id', $sam->id));

        $this->actingAs($sam)->post(route('account.emails'), [])->assertRedirect(route('account.emails'))->assertSessionHas('status', 'Saved.');

        $this->assertFalse($sam->fresh()->cover_request_emails);
        $this->assertFalse(CoverEligibility::recipients($shift)->contains('id', $sam->id));
        $entry = ChangeLogEntry::query()->ofAction('user.email_settings_changed')->sole();
        $this->assertSame($sam->id, $entry->actor_id);
        $this->assertSame($sam->id, $entry->affected_user_id);
        $this->assertSame(['cover_request_emails' => ['old' => true, 'new' => false]], $entry->changes);
        $this->assertSame('Turned off cover request emails', Describe::entry($entry, $sam));

        $this->actingAs($sam)->post(route('account.emails'), ['cover_request_emails' => '1'])->assertRedirect(route('account.emails'));

        $this->assertTrue($sam->fresh()->cover_request_emails);
        $this->assertTrue(CoverEligibility::recipients($shift)->contains('id', $sam->id));
        $this->assertSame(['cover_request_emails' => ['old' => false, 'new' => true]], ChangeLogEntry::query()->ofAction('user.email_settings_changed')->latest('id')->first()->changes);
        $this->assertSame('Turned on cover request emails', Describe::entry(ChangeLogEntry::query()->latest('id')->first(), $sam));
    }

    public function test_the_status_shows_on_the_page_after_saving(): void
    {
        $sam = User::factory()->create();

        $this->actingAs($sam)->followingRedirects()->post(route('account.emails'), ['cover_request_emails' => '1'])->assertSee('Saved.');
    }

    public function test_saving_without_a_change_logs_nothing(): void
    {
        $sam = User::factory()->create();

        $this->actingAs($sam)->post(route('account.emails'), ['cover_request_emails' => '1'])->assertSessionHas('status', 'Saved.');

        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.email_settings_changed')->count());
        app(UpdateEmailSettings::class)->handle($sam, true);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.email_settings_changed')->count());
    }

    public function test_the_change_log_filters_these_entries_under_accounts(): void
    {
        $this->assertContains('user.email_settings_changed', ChangeLogController::KINDS['invitations'][1]);
    }

    public function test_the_account_page_links_to_emails(): void
    {
        $this->actingAs(User::factory()->create())->get(route('account.show'))->assertSee('href="'.route('account.emails').'"', false);
    }
}
