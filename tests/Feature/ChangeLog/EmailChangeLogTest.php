<?php

namespace Tests\Feature\ChangeLog;

use App\Actions\Emails\SaveEmailWording;
use App\Enums\Role;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/** Wireframe 64: email edits in the change log, with the text before and after, and one email's history. */
class EmailChangeLogTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private User $coordinator;

    private User $robin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-11-24 14:14', 'America/Detroit'));
        Organization::factory()->create(['name' => 'Example Makers', 'timezone' => 'America/Detroit']);
        $this->coordinator = User::factory()->cleared()->create(['name' => 'Taylor Coordinator']);
        $this->coordinator->roles()->create(['role' => Role::Coordinator->value]);
        $this->enableTwoFactor($this->coordinator);
        $this->robin = User::factory()->cleared()->create(['name' => 'Robin Example']);
    }

    private function save(array $lines): void
    {
        app(SaveEmailWording::class)->handle($this->coordinator, $lines);
    }

    public function test_the_emails_kind_lists_only_email_edits(): void
    {
        $this->save(['shift_reminder.intro.regular.date' => 'Just a heads up: you\'re on {area} {date}, {time} ({timezone}).']);
        ChangeLogEntry::record($this->coordinator, 'user.invited', $this->robin);

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['kind' => 'emails']))
            ->assertOk()
            ->assertSee('Changed the shift reminder&#039;s opening', false)
            ->assertDontSee('Invited Robin')
            ->assertSee('1 change in Emails');
    }

    public function test_an_edit_shows_the_text_before_and_after_escaped_as_typed(): void
    {
        $this->save(['shift_reminder.intro.regular.date' => 'Just a heads up: <b>you\'re</b> on {area} {date}, {time} ({timezone}). **Arrive** [early] & sign in.']);

        $response = $this->actingAs($this->coordinator)->get(route('change-log.index'))->assertOk();

        $response->assertSee('Changed the shift reminder&#039;s opening', false);
        $response->assertSeeInOrder(['Before:', 'You&#039;re on {area} {date}, {time} ({timezone}).', 'After:', 'Just a heads up: &lt;b&gt;you&#039;re&lt;/b&gt; on {area} {date}, {time} ({timezone}). **Arrive** [early] &amp; sign in.'], false);
        $response->assertDontSee('<b>you', false);
    }

    public function test_a_reset_reads_as_putting_the_line_back(): void
    {
        $this->save(['shift_reminder.intro.regular.date' => 'Heads up: you\'re on {area} {date}, {time} ({timezone}).']);
        $this->save(['shift_reminder.intro.regular.date' => null]);

        $response = $this->actingAs($this->coordinator)->get(route('change-log.index', ['kind' => 'emails']))->assertOk();

        $response->assertSee('Put the shift reminder&#039;s opening back to the default', false);
        $response->assertSeeInOrder(['Put the shift reminder', 'Before:', 'Heads up: you&#039;re on', 'After:', 'You&#039;re on {area} {date}'], false);
    }

    public function test_emails_are_named_so_the_sentence_reads_plainly(): void
    {
        $this->save([
            'put_on_shift.subject' => 'You\'re on {area} now',
            'every_email.sign_off' => 'Thanks,',
            'login_link.intro' => 'Tap the button to log in now.',
        ]);

        $this->actingAs($this->coordinator)->get(route('change-log.index'))
            ->assertOk()
            ->assertSee('Changed the &quot;Put on a shift&quot; email&#039;s subject', false)
            ->assertSee('Changed every email&#039;s sign-off', false)
            ->assertSee('Changed the login link email&#039;s opening', false);
    }

    public function test_an_entry_for_a_line_no_email_has_any_more_still_reads(): void
    {
        ChangeLogEntry::record($this->coordinator, 'email_wording.changed', $this->coordinator, ['key' => 'gone_email.intro', 'email' => 'gone_email'], ['text' => ['old' => 'Old', 'new' => 'New']]);
        ChangeLogEntry::record($this->coordinator, 'email_wording.changed', $this->coordinator, ['key' => 'login_link.gone_line', 'email' => 'login_link'], ['text' => ['old' => 'Old', 'new' => 'New']]);

        $this->actingAs($this->coordinator)->get(route('change-log.index'))
            ->assertOk()
            ->assertSee('Changed a line of an email')
            ->assertSee('Changed a line of the login link email');
    }

    public function test_filtering_by_one_email_shows_its_whole_history(): void
    {
        // A reset deletes the row and the next edit makes a new one, so the history goes by the key, not the row.
        $this->save(['shift_reminder.intro.regular.date' => 'First try: you\'re on {area} {date}, {time} ({timezone}).']);
        $this->save(['shift_reminder.intro.regular.date' => null]);
        $this->save(['shift_reminder.intro.regular.date' => 'Second try: you\'re on {area} {date}, {time} ({timezone}).']);
        $this->save(['login_link.intro' => 'Tap the button to log in now.']);
        // Another email whose key starts the same way, to show the filter matches the whole email key.
        ChangeLogEntry::record($this->coordinator, 'email_wording.changed', $this->coordinator, ['key' => 'shift_reminders.intro', 'email' => 'shift_reminders'], ['text' => ['old' => 'Old', 'new' => 'Lookalike']]);
        ChangeLogEntry::record($this->coordinator, 'user.invited', $this->robin, ['key' => 'shift_reminder.intro']);

        $response = $this->actingAs($this->coordinator)->get(route('change-log.index', ['email' => 'shift_reminder']))->assertOk();

        $response->assertSee('3 changes to the &quot;Shift reminder&quot; email', false);
        $response->assertSee('First try');
        $response->assertSee('Second try');
        $response->assertDontSee('log in now');
        $response->assertDontSee('Lookalike');
        $response->assertDontSee('Invited Robin');
        $response->assertSee('<option value="shift_reminder" selected>Shift reminder</option>', false);
    }

    public function test_an_emails_history_includes_the_other_emails_lines_its_page_lists(): void
    {
        // The cancelled email's page lists the shift_restored lines, so their edits show in its history. They also show under shift_restored's own.
        $this->save(['shift_restored.body.one' => 'Good news: your shift is back on.']);
        $this->save(['shift_cancelled.body.one' => 'Sorry: your shift was cancelled.']);
        $this->save(['login_link.intro' => 'Tap the button to log in now.']);

        $cancelled = $this->actingAs($this->coordinator)->get(route('change-log.index', ['email' => 'shift_cancelled']))->assertOk();
        $cancelled->assertSee('Good news');
        $cancelled->assertSee('Sorry: your shift');
        $cancelled->assertDontSee('log in now');

        $restored = $this->actingAs($this->coordinator)->get(route('change-log.index', ['email' => 'shift_restored']))->assertOk();
        $restored->assertSee('Good news');
        $restored->assertDontSee('Sorry: your shift');
    }

    public function test_a_filter_for_an_email_that_isnt_in_the_catalog_is_ignored(): void
    {
        $this->save(['login_link.intro' => 'Tap the button to log in now.']);

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['email' => 'nope%']))
            ->assertOk()
            ->assertSee('log in now');
    }

    public function test_the_email_page_links_to_its_history(): void
    {
        $this->actingAs($this->coordinator)->get(route('emails.edit', 'shift_reminder'))
            ->assertOk()
            ->assertSee(route('change-log.index', ['kind' => 'emails', 'email' => 'shift_reminder']));
    }

    public function test_the_email_filter_is_ignored_with_another_kind(): void
    {
        $this->save(['login_link.intro' => 'Tap the button to log in now.']);
        ChangeLogEntry::record($this->coordinator, 'user.invited', $this->robin);

        $this->actingAs($this->coordinator)->get(route('change-log.index', ['kind' => 'invitations', 'email' => 'login_link']))
            ->assertOk()
            ->assertSee('1 change in Invitations and accounts')
            ->assertDontSee('to the &quot;Login link&quot; email', false)
            ->assertDontSee('log in now')
            ->assertDontSee('name="email"', false);
    }
}
