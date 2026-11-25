<?php

namespace Tests\Feature\Screens;

use App\Actions\Emails\SaveEmailWording;
use App\Emails\EmailCatalog;
use App\Emails\EmailPreview;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\EmailWording;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\LaravelMailLines;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Testing\TestResponse;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * The email editor (wireframes 61 to 63): the list of emails, one email's lines, saving, putting a line back and the preview. Only active coordinators and admins can reach any of it (spec section 6, Review Focus 3).
 */
class EmailEditorTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const OPENING = 'shift_reminder.intro.regular.date';

    private User $admin;

    private User $coordinator;

    private User $lead;

    private User $volunteer;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['name' => 'Example Makers', 'reminder_hours' => 24]);
        $area = Area::factory()->create(['name' => 'Front desk']);
        $this->admin = $this->person(Role::Admin, null, 'Morgan Admin');
        $this->coordinator = $this->person(Role::Coordinator, null, 'Chris Coordinator');
        $this->lead = $this->person(Role::AreaLead, $area, 'Casey Lead');
        $this->volunteer = User::factory()->cleared()->create(['name' => 'Vic Volunteer']);
    }

    private function person(Role $role, ?Area $area, string $name): User
    {
        $user = User::factory()->cleared()->create(['name' => $name]);
        $user->roles()->create(['role' => $role->value, 'area_id' => $area?->id]);
        $this->enableTwoFactor($user);

        return $user;
    }

    /**
     * Every route, with what it's sent: a save of one line, a preview of one line and one line back to the default.
     *
     * @return list<array{0: string, 1: string, 2: array<string, mixed>}>
     */
    private function routes(): array
    {
        $lines = ['lines' => ['login_link.intro' => 'Tap to log in.'], 'loaded' => ['login_link.intro' => 'Tap the button to log in.']];

        return [
            ['GET', route('emails.index'), []],
            ['GET', route('emails.edit', 'login_link'), []],
            ['PUT', route('emails.update', 'login_link'), $lines],
            ['POST', route('emails.preview', 'login_link'), $lines],
            ['DELETE', route('emails.reset', ['login_link', 'login_link.intro']), []],
        ];
    }

    /** Opens an email's page and saves the given lines with each box's text as the page loaded it, the way the form sends them. */
    private function saveLines(User $user, string $email, array $lines): TestResponse
    {
        return $this->actingAs($user)->put(route('emails.update', $email), ['lines' => $lines, 'loaded' => $this->loaded(array_keys($lines))]);
    }

    /** @param list<string> $keys The current text of each line, as the hidden inputs carry it. */
    private function loaded(array $keys): array
    {
        $loaded = [];
        foreach ($keys as $key) {
            $loaded[$key] = EmailWording::edits()[$key] ?? trans('emails.'.$key);
        }

        return $loaded;
    }

    public function test_volunteers_and_area_leads_cant_open_save_reset_or_preview(): void
    {
        foreach (['volunteer' => $this->volunteer, 'area lead' => $this->lead] as $who => $user) {
            foreach ($this->routes() as [$method, $url, $data]) {
                $this->actingAs($user)->call($method, $url, $data)->assertForbidden("{$method} {$url} should refuse a {$who}.");
            }
        }

        $this->assertDatabaseCount('email_wordings', 0);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'like', 'email_wording.%')->count());
    }

    public function test_a_turned_off_coordinator_is_signed_out_on_every_route(): void
    {
        $this->coordinator->forceFill(['deactivated_at' => now()])->save();

        foreach ($this->routes() as [$method, $url, $data]) {
            $this->actingAs($this->coordinator->fresh())->call($method, $url, $data)->assertRedirect(route('login'));
            $this->assertGuest();
        }

        $this->assertFalse(Gate::forUser($this->coordinator->fresh())->allows('manage-emails'));
        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_a_guest_is_sent_to_log_in_on_every_route(): void
    {
        foreach ($this->routes() as [$method, $url, $data]) {
            $this->call($method, $url, $data)->assertRedirect(route('login'));
        }

        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_coordinators_and_admins_can_use_every_route(): void
    {
        foreach ([$this->coordinator, $this->admin] as $user) {
            EmailWording::query()->delete();
            EmailWording::forgetCache();

            foreach ($this->routes() as [$method, $url, $data]) {
                $response = $this->actingAs($user)->call($method, $url, $data);
                $this->assertContains($response->status(), [200, 302], "{$method} {$url} should work for {$user->name}.");
                $response->assertSessionHasNoErrors();
            }
        }

        $this->assertTrue(Gate::forUser($this->coordinator)->allows('manage-emails'));
        $this->assertTrue(Gate::forUser($this->admin)->allows('manage-emails'));
    }

    public function test_a_forged_form_with_no_csrf_token_is_refused(): void
    {
        // The test kernel skips the token check, so this puts back one that doesn't.
        $this->app->bind(PreventRequestForgery::class, fn ($app) => new class($app, $app['encrypter']) extends PreventRequestForgery
        {
            protected function runningUnitTests()
            {
                return false;
            }
        });

        foreach (array_slice($this->routes(), 2) as [$method, $url, $data]) {
            $this->actingAs($this->coordinator)->call($method, $url, $data)->assertStatus(419);
        }

        $this->assertDatabaseCount('email_wordings', 0);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'like', 'email_wording.%')->count());

        // The same save with the page's token goes through, so the refusal above was the token.
        $this->actingAs($this->coordinator)->withSession(['_token' => 'page-token'])
            ->put(route('emails.update', 'login_link'), ['_token' => 'page-token'] + $this->routes()[2][2])
            ->assertRedirect(route('emails.edit', 'login_link'));
        $this->assertDatabaseHas('email_wordings', ['key' => 'login_link.intro', 'text' => 'Tap to log in.']);
    }

    public function test_every_form_on_the_edit_page_carries_a_csrf_token(): void
    {
        EmailWording::query()->create(['key' => 'login_link.intro', 'text' => 'Tap to log in.', 'updated_by' => $this->coordinator->id]);

        $html = $this->actingAs($this->coordinator)->get(route('emails.edit', 'login_link'))->assertOk()->getContent();

        preg_match_all('/<form method="POST"[^>]*>(.*?)<\/form>/s', $html, $forms);
        $this->assertCount(1, $forms[0], 'Every button, Back to the default included, is part of the one form.');
        $this->assertStringContainsString('name="_token"', $forms[1][0]);
        $this->assertStringContainsString('formaction="'.route('emails.reset', ['login_link', 'login_link.intro']).'" name="_method" value="DELETE"', $forms[1][0]);

        // Enter in a box presses the form's first button, which previews rather than putting a line back or saving.
        preg_match('/<button type="submit"[^>]*>/', $forms[1][0], $first);
        $this->assertStringContainsString('formaction="'.route('emails.preview', 'login_link').'"', $first[0]);
    }

    public function test_the_boxes_come_before_the_preview_in_the_page(): void
    {
        $html = $this->actingAs($this->coordinator)->get(route('emails.edit', 'shift_reminder'))->getContent();

        $this->assertLessThan(strpos($html, 'class="panel email-preview'), strpos($html, 'id="email-form"'));
        // In the preview, the email comes before the "Show it as" choice.
        $this->assertLessThan(strpos($html, 'Show it as it would reach:'), strpos($html, 'aria-label="Email preview"'));
    }

    public function test_back_to_the_default_keeps_what_was_typed_in_the_other_boxes(): void
    {
        app(SaveEmailWording::class)->handle($this->admin, ['login_link.outro' => 'This link works once.']);
        $loaded = $this->loaded(['login_link.subject', 'login_link.intro', 'login_link.outro']);
        $lines = ['login_link.subject' => 'Your link to log in', 'login_link.intro' => 'Tap below to log in.', 'login_link.outro' => 'This link works once.'];

        $this->actingAs($this->coordinator)->delete(route('emails.reset', ['login_link', 'login_link.outro']), ['lines' => $lines, 'loaded' => $loaded])
            ->assertRedirect(route('emails.edit', 'login_link'))
            ->assertSessionHas('status', 'That line is back to the default wording.');

        // Only the reset line changed; the typed lines weren't saved.
        $this->assertDatabaseCount('email_wordings', 0);
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'like', 'email_wording.%')->where('actor_id', $this->coordinator->id)->count());

        $response = $this->actingAs($this->coordinator)->get(route('emails.edit', 'login_link'));
        $response->assertSee('value="Your link to log in"', false);
        $response->assertSee('Tap below to log in.');
        $response->assertSee('value="Tap the button to log in."', false);
        $response->assertSee(e(trans('emails.login_link.outro')), false);
        $response->assertDontSee('This link works once.');
    }

    public function test_going_too_fast_gets_a_plain_page(): void
    {
        $data = $this->routes()[3][2];
        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->coordinator)->post(route('emails.preview', 'login_link'), $data);
        }

        $response = $this->actingAs($this->coordinator)->put(route('emails.update', 'login_link'), $data)->assertTooManyRequests();
        $response->assertSee('<h1>You\'re going a little fast</h1>', false);
        $response->assertSee('Wait a minute, then go back and try again. Use your browser\'s back button so what you typed is still there.', false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
    }

    public function test_an_unknown_email_isnt_found(): void
    {
        $this->actingAs($this->coordinator)->get(route('emails.edit', 'no_such_email'))->assertNotFound();
        $this->actingAs($this->coordinator)->put(route('emails.update', 'no_such_email'), ['lines' => ['login_link.intro' => 'x']])->assertNotFound();
    }

    public function test_a_line_from_another_email_is_refused(): void
    {
        $this->actingAs($this->coordinator)->from(route('emails.edit', 'login_link'))->put(route('emails.update', 'login_link'), [
            'lines' => ['login_link.intro' => 'Tap to log in.', 'two_factor_code.code' => 'Your code: {code}.'],
            'loaded' => ['login_link.intro' => 'Tap the button to log in.', 'two_factor_code.code' => trans('emails.two_factor_code.code')],
        ])->assertRedirect(route('emails.edit', 'login_link'))->assertSessionHasErrors('lines');

        $this->actingAs($this->coordinator)->post(route('emails.preview', 'login_link'), ['lines' => ['two_factor_code.code' => 'Your code: {code}.']])->assertSessionHasErrors('lines');
        $this->actingAs($this->coordinator)->delete(route('emails.reset', ['login_link', 'two_factor_code.code']))->assertNotFound();
        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_a_line_that_isnt_text_is_refused(): void
    {
        $this->actingAs($this->coordinator)->from(route('emails.edit', 'login_link'))->put(route('emails.update', 'login_link'), [
            'lines' => ['login_link.intro' => ['Tap', 'to log in']],
            'loaded' => ['login_link.intro' => 'Tap the button to log in.'],
        ])->assertSessionHasErrors();

        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_saving_resetting_and_previewing_are_rate_limited(): void
    {
        $data = $this->routes()[3][2];

        for ($i = 0; $i < 30; $i++) {
            $this->actingAs($this->coordinator)->post(route('emails.preview', 'login_link'), $data)->assertOk();
        }

        $this->actingAs($this->coordinator)->post(route('emails.preview', 'login_link'), $data)->assertTooManyRequests();
        $this->actingAs($this->coordinator)->put(route('emails.update', 'login_link'), $data)->assertTooManyRequests();
        $this->actingAs($this->coordinator)->delete(route('emails.reset', ['login_link', 'login_link.intro']))->assertTooManyRequests();
        // Another person has their own allowance.
        $this->actingAs($this->admin)->post(route('emails.preview', 'login_link'), $data)->assertOk();
    }

    public function test_the_list_groups_every_email_and_marks_edited_ones(): void
    {
        app(SaveEmailWording::class)->handle($this->coordinator, [self::OPENING => 'Just a heads up: you\'re on {area} {date}, {time} ({timezone}).']);

        $response = $this->actingAs($this->coordinator)->get(route('emails.index'))->assertOk();

        $response->assertSeeInOrder(['<h1>Emails we send</h1>', 'To volunteers about their own shifts', 'Shift reminder', 'Edited', 'Removed from shifts', 'Asking for cover', 'Cover request', 'Shadowing', 'To shift leads and coordinators', 'Logging in and accounts', 'Login link', 'Every email'], false);
        $response->assertSee(route('emails.edit', 'shift_reminder'), false);
        $response->assertSee('24 hours before each shift starts');
        $this->assertSame(1, substr_count($response->getContent(), '>Edited<'));
    }

    public function test_the_edit_page_shows_when_its_sent_each_line_and_the_variables(): void
    {
        app(SaveEmailWording::class)->handle($this->coordinator, [self::OPENING => 'Just a heads up: you\'re on {area} {date}, {time} ({timezone}).']);

        $response = $this->actingAs($this->coordinator)->get(route('emails.edit', 'shift_reminder'))->assertOk();

        $response->assertSee('<h1>Shift reminder</h1>', false);
        $response->assertSeeInOrder(['When it\'s sent', '24 hours before each shift starts'], false);
        // A labelled box per line, with when it shows.
        $response->assertSee('<label for="line-shift_reminder-greeting">Greeting</label>', false);
        $response->assertSee('name="lines[shift_reminder.greeting]"', false);
        $response->assertSee('Shows for someone on the shift when it&#039;s on a later day', false);
        // The edited line shows its default and a way back to it.
        $response->assertSee('Just a heads up: you&#039;re on {area} {date}, {time} ({timezone}).', false);
        $response->assertSee('Default: “You&#039;re on {area} {date}, {time} ({timezone}).”', false);
        $response->assertSee('Back to the default');
        $response->assertSee(route('emails.reset', ['shift_reminder', self::OPENING]), false);
        $this->assertSame(1, substr_count($response->getContent(), '>Back to the default<span class="vh"> for '));
        // Phrases used inside other lines have their own heading.
        $response->assertSeeInOrder(['Used inside other lines', 'Words that link to the Emails page'], false);
        // The words in braces, with examples.
        $response->assertSeeInOrder(['Words you can use in this email', '{first_name}', 'Sam', '{emails_page_link}', 'a link reading “Emails page”'], false);
        // Short lines get one-line boxes, long ones a box with room.
        $response->assertSee('<input type="text" id="line-shift_reminder-greeting"', false);
        $response->assertSee('<textarea id="line-shift_reminder-nobody_to_shadow"', false);
    }

    public function test_saving_shows_a_status_and_keeps_the_other_lines(): void
    {
        app(SaveEmailWording::class)->handle($this->admin, ['login_link.outro' => 'This link works once.']);
        $all = ['login_link.subject', 'login_link.intro', 'login_link.action', 'login_link.outro'];
        $lines = $this->loaded($all);
        $lines['login_link.intro'] = 'Tap the button below to log in.';

        $this->saveLines($this->coordinator, 'login_link', $lines)
            ->assertRedirect(route('emails.edit', 'login_link'))
            ->assertSessionHas('status', 'Saved. Emails sent from now on use the new wording.');

        $this->assertDatabaseHas('email_wordings', ['key' => 'login_link.intro', 'text' => 'Tap the button below to log in.', 'updated_by' => $this->coordinator->id]);
        $this->assertDatabaseHas('email_wordings', ['key' => 'login_link.outro', 'text' => 'This link works once.', 'updated_by' => $this->admin->id]);
        $this->assertDatabaseCount('email_wordings', 2);
        // Only the changed line is logged.
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'email_wording.changed')->where('actor_id', $this->coordinator->id)->count());

        $this->actingAs($this->coordinator)->get(route('emails.edit', 'login_link'))
            ->assertSee('<p role="status" class="cal-status">Saved. Emails sent from now on use the new wording.</p>', false)
            ->assertSee('Tap the button below to log in.');
    }

    public function test_saving_with_nothing_changed_says_so(): void
    {
        $this->saveLines($this->coordinator, 'login_link', $this->loaded(['login_link.intro', 'login_link.outro']))
            ->assertSessionHas('status', 'Nothing changed, so there was nothing to save.');

        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_a_refused_save_shows_the_message_beside_the_line_and_keeps_what_was_typed(): void
    {
        $lines = $this->loaded(['shift_reminder.greeting', 'shift_reminder.turn_off']);
        $lines['shift_reminder.greeting'] = 'Hello {first_name},';
        $lines['shift_reminder.turn_off'] = 'You can turn these reminders off on the {emails_page}.';

        $this->actingAs($this->coordinator)->from(route('emails.edit', 'shift_reminder'));
        $this->saveLines($this->coordinator, 'shift_reminder', $lines)->assertRedirect(route('emails.edit', 'shift_reminder'));

        // Nothing is saved, not even the line that was fine.
        $this->assertDatabaseCount('email_wordings', 0);

        $response = $this->actingAs($this->coordinator)->get(route('emails.edit', 'shift_reminder'));
        $response->assertSee('<span class="field-error" id="line-shift_reminder-turn_off-error">{emails_page} isn&#039;t a word this line can use. Did you mean {emails_page_link}?</span>', false);
        $response->assertSee('aria-invalid="true"', false);
        $response->assertSee('value="You can turn these reminders off on the {emails_page}."', false);
        $response->assertSee('value="Hello {first_name},"', false);
        $response->assertSee('Nothing was saved. Check the lines marked below.');
    }

    public function test_an_unknown_word_with_nothing_close_lists_the_words_the_line_can_use(): void
    {
        $lines = $this->loaded(['login_link.intro']);
        $lines['login_link.intro'] = 'Tap {zebra} to log in.';

        $this->saveLines($this->coordinator, 'login_link', $lines)
            ->assertSessionHasErrors(['lines.login_link.intro' => '{zebra} isn\'t a word this line can use. This line has no words in braces you can use.']);
    }

    public function test_a_cleared_box_is_refused_rather_than_put_back(): void
    {
        app(SaveEmailWording::class)->handle($this->admin, ['login_link.intro' => 'Tap to log in.']);
        $lines = $this->loaded(['login_link.intro']);
        $lines['login_link.intro'] = '';

        $this->saveLines($this->coordinator, 'login_link', $lines)
            ->assertSessionHasErrors(['lines.login_link.intro' => 'Type the wording, or use Back to the default.']);

        $this->assertDatabaseHas('email_wordings', ['key' => 'login_link.intro', 'text' => 'Tap to log in.']);
    }

    public function test_a_line_someone_else_changed_meanwhile_is_refused_with_their_version(): void
    {
        // The page loaded with the defaults; then someone else saved the opening.
        $loaded = $this->loaded(['login_link.intro', 'login_link.outro']);
        app(SaveEmailWording::class)->handle($this->admin, ['login_link.intro' => 'Their version.']);

        $this->actingAs($this->coordinator)->put(route('emails.update', 'login_link'), [
            'lines' => ['login_link.intro' => 'My version.', 'login_link.outro' => 'My outro.'],
            'loaded' => $loaded,
        ])->assertRedirect(route('emails.edit', 'login_link'))
            ->assertSessionHasErrors(['lines.login_link.intro' => 'Someone else changed this line while you were editing. Here\'s their version; make your change again.']);

        // Nothing from that submission is saved, not even the outro.
        $this->assertDatabaseHas('email_wordings', ['key' => 'login_link.intro', 'text' => 'Their version.']);
        $this->assertDatabaseMissing('email_wordings', ['key' => 'login_link.outro']);

        $response = $this->actingAs($this->coordinator)->get(route('emails.edit', 'login_link'));
        $response->assertSee('value="Their version."', false);
        $response->assertSee('<input type="hidden" name="loaded[login_link.intro]" value="Their version.">', false);
        $response->assertSee('My outro.');
    }

    public function test_back_to_the_default_from_a_stale_page_is_refused_with_their_version(): void
    {
        // The page loaded with one edit; then someone else saved a newer one.
        app(SaveEmailWording::class)->handle($this->admin, ['login_link.intro' => 'Tap to log in.']);
        $loaded = $this->loaded(['login_link.intro', 'login_link.outro']);
        app(SaveEmailWording::class)->handle($this->admin, ['login_link.intro' => 'Their newer version.']);

        $this->actingAs($this->coordinator)->delete(route('emails.reset', ['login_link', 'login_link.intro']), [
            'lines' => ['login_link.outro' => 'My outro.'],
            'loaded' => $loaded,
        ])->assertRedirect(route('emails.edit', 'login_link'))
            ->assertSessionHasErrors(['lines.login_link.intro' => 'Someone else changed this line while you were editing. Here\'s their version; make your change again.']);

        $this->assertDatabaseHas('email_wordings', ['key' => 'login_link.intro', 'text' => 'Their newer version.']);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'email_wording.reset')->count());

        $this->actingAs($this->coordinator)->get(route('emails.edit', 'login_link'))->assertSee('value="Their newer version."', false);
    }

    public function test_back_to_the_default_puts_one_line_back(): void
    {
        app(SaveEmailWording::class)->handle($this->admin, ['login_link.intro' => 'Tap to log in.', 'login_link.outro' => 'This link works once.']);

        $this->actingAs($this->coordinator)->delete(route('emails.reset', ['login_link', 'login_link.intro']))
            ->assertRedirect(route('emails.edit', 'login_link'))
            ->assertSessionHas('status', 'That line is back to the default wording.');

        $this->assertDatabaseMissing('email_wordings', ['key' => 'login_link.intro']);
        $this->assertDatabaseHas('email_wordings', ['key' => 'login_link.outro']);
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'email_wording.reset')->where('actor_id', $this->coordinator->id)->count());
    }

    public function test_preview_shows_the_typed_text_without_saving(): void
    {
        Notification::fake();
        $lines = $this->loaded(['shift_reminder.greeting', self::OPENING, 'shift_reminder.turn_off', 'shift_reminder.emails_page_label']);
        $lines[self::OPENING] = 'Just a heads up: you\'re on {area} {date}, {time} ({timezone}). <script>alert(1)</script> **bold**';
        $lines['shift_reminder.emails_page_label'] = 'email settings';

        $response = $this->actingAs($this->coordinator)->post(route('emails.preview', 'shift_reminder'), ['lines' => $lines, 'loaded' => $this->loaded(array_keys($lines))])->assertOk();

        $response->assertSee('<h1>Shift reminder</h1>', false);
        $response->assertSeeInOrder(['Preview', 'Reminder: Front desk on Wednesday, October 21, 6:30 to 9:30pm', 'Hi Sam,', 'Just a heads up: you\'re on Front desk Wednesday, October 21, 6:30 to 9:30pm (Eastern Time).'], false);
        // Typed markup reads as typed, never as markup.
        $this->assertStringNotContainsString('<script>alert(1)</script>', $response->getContent());
        $this->assertStringNotContainsString('<strong>bold</strong>', $response->getContent());
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
        // A phrase typed for inside another line shows inside it, as the link text.
        $response->assertSee('<a href="https://example.org/">email settings</a>', false);
        $response->assertSee('This preview uses your unsaved changes and example people. Nothing is sent.');
        // The boxes keep what was typed.
        $response->assertSee('name="lines[shift_reminder.emails_page_label]" value="email settings"', false);

        $this->assertDatabaseCount('email_wordings', 0);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'like', 'email_wording.%')->count());
        Notification::assertNothingSent();
    }

    public function test_preview_with_a_refused_line_shows_the_message_and_saves_nothing(): void
    {
        $lines = $this->loaded(['login_link.intro']);
        $lines['login_link.intro'] = 'Go to https://example.org now.';

        $response = $this->actingAs($this->coordinator)->post(route('emails.preview', 'login_link'), ['lines' => $lines, 'loaded' => $this->loaded(['login_link.intro'])])->assertOk();

        $response->assertSee('Emails can&#039;t have web addresses typed into them. Links come from SSVS.', false);
        $this->assertSame(1, substr_count($response->getContent(), 'Fix the lines marked below to see the preview.'));
        $response->assertDontSee('Nothing was saved.');
        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_preview_can_show_each_version(): void
    {
        $page = $this->actingAs($this->coordinator)->get(route('emails.edit', 'shift_reminder'))->assertOk();
        $page->assertSeeInOrder(['Show it as it would reach:', 'Someone on the shift', 'A shadow', 'A shadow with nobody to shadow', 'Someone with an unanswered time change'], false);
        $page->assertSee('type="radio" name="version" value="Someone on the shift" form="email-form" checked', false);

        $shadow = $this->actingAs($this->coordinator)->post(route('emails.preview', 'shift_reminder'), ['version' => 'A shadow with nobody to shadow', 'lines' => [], 'loaded' => []])->assertOk();
        $shadow->assertSee('value="A shadow with nobody to shadow" form="email-form" checked', false);
        $shadow->assertSeeInOrder(['Preview', 'You\'re shadowing Front desk Wednesday, October 21', 'Nobody is on this shift for you to shadow yet.'], false);

        $changed = $this->actingAs($this->coordinator)->post(route('emails.preview', 'shift_reminder'), ['version' => 'Someone with an unanswered time change', 'lines' => [], 'loaded' => []])->assertOk();
        $changed->assertSeeInOrder(['The time of this shift changed', 'Still works for me', 'I can\'t make the new time'], false);

        // A made-up version shows the first one.
        $this->actingAs($this->coordinator)->post(route('emails.preview', 'shift_reminder'), ['version' => 'Nonsense', 'lines' => [], 'loaded' => []])
            ->assertOk()->assertSee('value="Someone on the shift" form="email-form" checked', false);
    }

    public function test_a_version_with_alternatives_shows_the_one_its_count_picks(): void
    {
        $one = $this->actingAs($this->coordinator)->post(route('emails.preview', 'cover_urgent'), ['version' => 'One shift, one more person needed', 'lines' => [], 'loaded' => []])->assertOk();
        $one->assertSee('We emailed 1 volunteer when it was released.');
        $one->assertDontSee('We emailed 1 volunteers');

        $several = $this->actingAs($this->coordinator)->post(route('emails.preview', 'cover_urgent'), ['version' => 'Several shifts', 'lines' => [], 'loaded' => []])->assertOk();
        $several->assertSee('Needs 2 more people.');
        $several->assertDontSee('Needs 2 more person.');
    }

    public function test_every_version_of_every_email_previews(): void
    {
        foreach (EmailCatalog::all() as $email) {
            foreach (array_keys($email->versions) as $version) {
                $preview = EmailPreview::render($email, $version, []);
                $this->assertNotSame([], $preview['blocks'], "{$email->key}: {$version}");
            }
        }

        // And one through the page, as the form sends it.
        $this->actingAs($this->coordinator)->post(route('emails.preview', 'cover_urgent'), ['version' => 'Several shifts', 'lines' => [], 'loaded' => []])
            ->assertOk()->assertSessionHasNoErrors();
    }

    public function test_the_every_email_page_edits_the_four_lines_laravel_adds(): void
    {
        $response = $this->actingAs($this->coordinator)->get(route('emails.edit', 'every_email'))->assertOk();

        $response->assertSee('<h1>Every email</h1>', false);
        foreach (['heading', 'sign_off', 'button_trouble', 'footer'] as $line) {
            $response->assertSee('name="lines[every_email.'.$line.']"', false);
        }
        $response->assertSee('{button}');
        $response->assertDontSee('can\'t be changed here yet');

        $this->actingAs($this->coordinator)->put(route('emails.update', 'every_email'), [
            'lines' => ['every_email.sign_off' => 'Thanks,'],
            'loaded' => ['every_email.sign_off' => 'Regards,'],
        ])->assertRedirect(route('emails.edit', 'every_email'));
        $this->assertDatabaseHas('email_wordings', ['key' => 'every_email.sign_off', 'text' => 'Thanks,']);

        $this->actingAs($this->coordinator)->post(route('emails.preview', 'every_email'), [
            'lines' => ['every_email.button_trouble' => 'Trouble with "{button}"? Use the address below.'],
            'loaded' => [],
        ])->assertOk()->assertSee('Trouble with &quot;Log in&quot;? Use the address below.', false)->assertSee('Thanks,');
    }

    public function test_the_footer_refuses_marks_that_would_become_formatting(): void
    {
        foreach (['See [our page](example.org)', '*Thanks*', 'snake_case', 'a <b>bold</b> one', 'back\\slash'] as $typed) {
            $this->actingAs($this->coordinator)->put(route('emails.update', 'every_email'), [
                'lines' => ['every_email.footer' => $typed],
                'loaded' => ['every_email.footer' => 'All rights reserved.'],
            ])->assertSessionHasErrors(['lines.every_email.footer' => 'The footer can\'t use [ ] * _ ` ~ | \\ < or >, because emails would turn them into formatting or links.']);
        }
        $this->assertDatabaseCount('email_wordings', 0);
    }

    public function test_the_footer_refuses_entities_that_spell_out_an_address(): void
    {
        foreach (['https&#58;//evil.example/x', '&#91;x&#93;(https&#58;&#47;&#47;evil.example)', 'www&#46;evil.example', 'Thanks &amp; bye'] as $typed) {
            $this->actingAs($this->coordinator)->put(route('emails.update', 'every_email'), [
                'lines' => ['every_email.footer' => $typed],
                'loaded' => ['every_email.footer' => 'All rights reserved.'],
            ])->assertSessionHasErrors(['lines.every_email.footer' => 'The footer can\'t contain codes that start with & and end with ;.']);
        }
        $this->assertDatabaseCount('email_wordings', 0);

        // A plain ampersand is fine.
        $this->actingAs($this->coordinator)->put(route('emails.update', 'every_email'), [
            'lines' => ['every_email.footer' => 'Thanks & see you soon.'],
            'loaded' => ['every_email.footer' => 'All rights reserved.'],
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('email_wordings', ['key' => 'every_email.footer', 'text' => 'Thanks & see you soon.']);
    }

    public function test_the_footer_check_also_reads_decoded_text(): void
    {
        $this->assertNull(LaravelMailLines::footerProblem('Thanks & see you soon.'));
        $this->assertSame(SaveEmailWording::WEB_ADDRESS, app(SaveEmailWording::class)->problemWith('every_email.footer', 'see www.example.org'));
    }

    public function test_a_line_can_have_its_own_example_people(): void
    {
        $response = $this->actingAs($this->coordinator)->post(route('emails.preview', 'shift_reminder'), ['version' => 'Someone on the shift', 'lines' => [], 'loaded' => []])->assertOk();

        $response->assertSee('Also on this shift: Jordan P.');
        $response->assertSee('Shadowing: Casey M.');
        $response->assertDontSee('Shadowing: Jordan P.');
    }

    public function test_links_to_the_editor_show_for_coordinators_and_admins_only(): void
    {
        foreach ([$this->coordinator, $this->admin] as $user) {
            $this->actingAs($user)->get(route('home'))->assertSee(route('emails.index'), false)->assertSee('Emails we send');
            $this->actingAs($user)->get(route('calendar.week'))->assertSee(route('emails.index'), false);
        }

        foreach ([$this->lead, $this->volunteer] as $user) {
            $this->actingAs($user)->get(route('home'))->assertDontSee('Emails we send');
            $this->actingAs($user)->get(route('calendar.week'))->assertDontSee('Emails we send');
        }
    }
}
