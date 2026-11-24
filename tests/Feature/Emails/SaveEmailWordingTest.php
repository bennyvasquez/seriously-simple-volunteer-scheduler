<?php

namespace Tests\Feature\Emails;

use App\Actions\Emails\SaveEmailWording;
use App\Models\ChangeLogEntry;
use App\Models\EmailWording;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\EmailText;
use App\Notifications\LoginLinkNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class SaveEmailWordingTest extends TestCase
{
    use RefreshDatabase;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['name' => 'Example Makers']);
        $this->coordinator = User::factory()->cleared()->create(['name' => 'Chris Coordinator', 'email' => 'chris@example.org']);
    }

    private function save(array $lines): void
    {
        app(SaveEmailWording::class)->handle($this->coordinator, $lines);
    }

    /** Runs the save and returns the refusal's messages, failing the test when it isn't refused. */
    private function refusal(array $lines): array
    {
        try {
            $this->save($lines);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        $this->fail('The save was not refused.');
    }

    public function test_an_edited_line_is_used_instead_of_the_default(): void
    {
        $this->save(['two_factor_code.code' => 'Here is your code: {code}.']);

        $this->assertSame('Here is your code: 123456.', (string) EmailText::line('two_factor_code.code', ['code' => '123456']));
        $this->assertDatabaseHas('email_wordings', ['key' => 'two_factor_code.code', 'text' => 'Here is your code: {code}.', 'updated_by' => $this->coordinator->id]);
    }

    public function test_putting_a_line_back_uses_the_default_again(): void
    {
        $this->save(['two_factor_code.code' => 'Here is your code: {code}.']);
        $this->save(['two_factor_code.code' => null]);

        $this->assertSame('Your code is 123456. It works for 10 minutes.', EmailText::line('two_factor_code.code', ['code' => '123456']));
        $this->assertDatabaseMissing('email_wordings', ['key' => 'two_factor_code.code']);

        $this->save(['two_factor_code.code' => 'Here is your code: {code}.']);
        $this->save(['two_factor_code.code' => null]);

        $this->assertSame('Your code is 123456. It works for 10 minutes.', EmailText::line('two_factor_code.code', ['code' => '123456']));
        $this->assertSame(2, ChangeLogEntry::query()->where('action', 'email_wording.reset')->count());
    }

    public function test_an_edit_with_an_unknown_variable_is_refused(): void
    {
        $errors = $this->refusal(['two_factor_code.code' => 'Your code is {code} for {first_name}.']);
        $this->assertSame(['two_factor_code.code'], array_keys($errors));
        $this->assertStringContainsString('{first_name}', $errors['two_factor_code.code'][0]);

        // A misspelling, and braces around something that isn't quite a variable, are refused too, rather than showing the braces in the email.
        $this->assertSame(['two_factor_code.code'], array_keys($this->refusal(['two_factor_code.code' => 'Your code is {cod}.'])));
        $this->assertSame(['two_factor_code.code'], array_keys($this->refusal(['two_factor_code.code' => 'Your code is {Code}.'])));
        $this->assertSame(['two_factor_code.code'], array_keys($this->refusal(['two_factor_code.code' => 'Your code is { code }.'])));

        $this->assertDatabaseCount('email_wordings', 0);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'like', 'email_wording.%')->count());
    }

    public function test_an_empty_or_too_long_line_is_refused(): void
    {
        // Only null puts a line back to the default: a cleared box, spaces or invisible characters alone are refused.
        foreach (['', "   \n  ", "\u{200B}\u{FEFF}"] as $empty) {
            $this->assertSame(['login_link.intro' => ['Type the wording, or use Back to the default.']], $this->refusal(['login_link.intro' => $empty]));
        }
        $this->assertSame(['login_link.intro'], array_keys($this->refusal(['login_link.intro' => str_repeat('a', 1001)])));

        // A refusal of one line stops the whole save.
        $this->assertSame(['login_link.outro'], array_keys($this->refusal(['login_link.intro' => 'Fine.', 'login_link.outro' => str_repeat('a', 1001)])));

        $this->assertDatabaseCount('email_wordings', 0);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'like', 'email_wording.%')->count());

        $this->save(['login_link.intro' => str_repeat('a', 1000)]);
        $this->assertDatabaseCount('email_wordings', 1);
    }

    public function test_an_edit_with_a_web_address_is_refused(): void
    {
        foreach (['See https://example.org for more.', 'Go to HTTP://example.org', 'Visit www.example.org', 'Visit WWW.example.org', 'Open ftp://example.org'] as $text) {
            $this->assertSame(['login_link.intro' => ['Emails can\'t have web addresses typed into them. Links come from SSVS.']], $this->refusal(['login_link.intro' => $text]), $text);
        }

        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_the_note_variable_cant_be_removed(): void
    {
        $errors = $this->refusal(['cover_request.note' => '{releaser} left a note.']);
        $this->assertStringContainsString('{note}', $errors['cover_request.note'][0]);

        $this->save(['cover_request.note' => 'A note from {releaser}: {note}']);
        $this->assertDatabaseHas('email_wordings', ['key' => 'cover_request.note', 'text' => 'A note from {releaser}: {note}']);
    }

    public function test_format_characters_are_removed_before_checking_and_storing(): void
    {
        // A right-to-left override and zero-width characters, which could hide or reorder what the text says.
        $this->save(['login_link.intro' => "Tap\u{202E}the\u{200B} button\u{200D}.\u{2066}"]);

        $this->assertSame('Tapthe button.', EmailWording::query()->where('key', 'login_link.intro')->value('text'));

        // A variable split by a zero-width space is checked as the variable it reads as.
        $this->save(['two_factor_code.code' => "Code: {co\u{200B}de}."]);
        $this->assertSame('Code: {code}.', EmailWording::query()->where('key', 'two_factor_code.code')->value('text'));
    }

    public function test_a_first_edit_saved_by_someone_else_at_the_same_moment_is_refused_plainly(): void
    {
        // The other save lands between this save's check and its insert.
        EmailWording::creating(function (EmailWording $wording) {
            DB::table('email_wordings')->insertOrIgnore(['key' => $wording->key, 'text' => 'Someone else\'s edit.', 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()]);
        });

        $this->assertSame(['login_link.intro' => ['Someone else just changed this line. Check it and try again.']], $this->refusal(['login_link.intro' => 'My edit.']));
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'like', 'email_wording.%')->count());
    }

    public function test_a_key_that_isnt_a_line_is_refused(): void
    {
        foreach (['login_link.nope', 'login_link', 'removed_from_shifts.subject', 'nothing.at.all', 'emails.login_link.intro'] as $key) {
            $this->assertSame([$key], array_keys($this->refusal([$key => 'Hello.'])), $key);
        }

        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_a_link_variable_cant_be_removed(): void
    {
        Lang::get('emails.login_link.intro');
        app('translator')->addLines(['emails.test_email.with_link' => 'You can turn these off on {emails_page_link}, {first_name}.'], 'en');

        $errors = $this->refusal(['test_email.with_link' => 'You can turn these off, {first_name}.']);
        $this->assertStringContainsString('{emails_page_link}', $errors['test_email.with_link'][0]);

        // Other variables can be left out, and the link can move.
        $this->save(['test_email.with_link' => 'Turn these off on {emails_page_link}.']);
        $this->assertDatabaseHas('email_wordings', ['key' => 'test_email.with_link', 'text' => 'Turn these off on {emails_page_link}.']);
    }

    public function test_saving_logs_old_and_new_text(): void
    {
        $this->save(['login_link.intro' => 'Press the button.', 'login_link.outro' => 'It expires soon.']);

        $entries = ChangeLogEntry::query()->where('action', 'email_wording.changed')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertSame(['key' => 'login_link.intro', 'email' => 'login_link'], $entries[0]->details);
        $this->assertSame(['text' => ['old' => 'Tap the button to log in.', 'new' => 'Press the button.']], $entries[0]->changes);
        $this->assertSame($this->coordinator->id, $entries[0]->actor_id);
        $this->assertSame('Chris Coordinator', $entries[0]->actor_name);
        $this->assertSame('login_link.outro', $entries[1]->details['key']);

        $this->save(['login_link.intro' => 'Press it.']);
        $this->assertSame(['text' => ['old' => 'Press the button.', 'new' => 'Press it.']], ChangeLogEntry::query()->where('action', 'email_wording.changed')->latest('id')->first()->changes);

        $this->save(['login_link.intro' => null]);
        $reset = ChangeLogEntry::query()->where('action', 'email_wording.reset')->sole();
        $this->assertSame(['key' => 'login_link.intro', 'email' => 'login_link'], $reset->details);
        $this->assertSame(['text' => ['old' => 'Press it.', 'new' => 'Tap the button to log in.']], $reset->changes);
    }

    public function test_saving_unchanged_text_logs_nothing(): void
    {
        $this->save(['login_link.intro' => 'Press the button.']);
        $before = ChangeLogEntry::query()->count();

        $this->save(['login_link.intro' => 'Press the button.']);
        // Extra spaces around it are the same text.
        $this->save(['login_link.intro' => '  Press the button. ']);
        // The default, for a line that isn't edited, and back to the default for a line that isn't edited.
        $this->save(['login_link.outro' => EmailText::line('login_link.outro'), 'login_link.action' => null]);

        $this->assertSame($before, ChangeLogEntry::query()->count());
        $this->assertDatabaseCount('email_wordings', 1);
    }

    public function test_saving_only_writes_lines_that_changed(): void
    {
        $this->save(['login_link.intro' => 'First edit.', 'login_link.outro' => 'Second edit.']);
        $this->travel(5)->minutes();

        // Another coordinator changes the outro.
        $other = User::factory()->cleared()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
        app(SaveEmailWording::class)->handle($other, ['login_link.outro' => 'Robin\'s edit.']);
        $this->travel(5)->minutes();

        // This save sends only the intro, which changed, and leaves the outro as Robin left it.
        $this->save(['login_link.intro' => 'Third edit.']);

        $outro = EmailWording::query()->where('key', 'login_link.outro')->sole();
        $this->assertSame('Robin\'s edit.', $outro->text);
        $this->assertSame($other->id, $outro->updated_by);

        // A save carrying an unchanged line alongside a changed one writes and logs only the changed one.
        $outroUpdatedAt = $outro->updated_at->toDateTimeString();
        $this->travel(5)->minutes();
        $logged = ChangeLogEntry::query()->count();
        $this->save(['login_link.intro' => 'Fourth edit.', 'login_link.outro' => 'Robin\'s edit.']);

        $this->assertSame($outroUpdatedAt, EmailWording::query()->where('key', 'login_link.outro')->sole()->updated_at->toDateTimeString());
        $this->assertSame($other->id, EmailWording::query()->where('key', 'login_link.outro')->sole()->updated_by);
        $this->assertSame($logged + 1, ChangeLogEntry::query()->count());
    }

    public function test_a_stored_edit_that_cant_be_filled_falls_back_to_the_default(): void
    {
        Exceptions::fake();
        DB::table('email_wordings')->insert(['key' => 'login_link.intro', 'text' => 'Hello {nonsense}, tap below.', 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()]);
        $sam = User::factory()->create(['name' => 'Sam Example', 'email' => 'sam@example.org']);

        $sam->notifyNow(new LoginLinkNotification('https://example.org/login/example-token'));

        $email = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $this->assertStringContainsString('Tap the button to log in.', (string) $email->getHtmlBody());
        $this->assertStringContainsString('Tap the button to log in.', (string) $email->getTextBody());
        $this->assertStringNotContainsString('nonsense', (string) $email->getTextBody());
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'login_link.intro') && str_contains($e->getMessage(), '{nonsense}'));
    }

    public function test_a_stored_edit_without_its_link_falls_back_to_the_default(): void
    {
        Exceptions::fake();
        Lang::get('emails.login_link.intro');
        app('translator')->addLines(['emails.test_email.with_link' => 'Turn these off on {emails_page_link}.'], 'en');
        DB::table('email_wordings')->insert(['key' => 'test_email.with_link', 'text' => 'No way to turn these off.', 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()]);

        $line = EmailText::line('test_email.with_link', ['emails_page_link' => new HtmlString('[Emails page](https://example.org/account/emails)')]);

        $this->assertSame('Turn these off on [Emails page](https://example.org/account/emails).', (string) $line);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'test_email.with_link'));
    }
}
