<?php

namespace Tests\Feature\Security;

use App\Emails\CatalogEmail;
use App\Emails\EmailCatalog;
use App\Emails\EmailPreview;
use App\Models\Assignment;
use App\Models\EmailWording;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\EmailText;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\ShadowSignedUpLeadNotification;
use App\Notifications\ShiftReminderNotification;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Events\NotificationFailed;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Lang;
use RuntimeException;
use Tests\Concerns\BuildsEmailExamples;
use Tests\TestCase;

/**
 * Everything filled into an email (people's names, area names, titles, reasons and the organization's name) reads exactly as typed, in every line, in both the HTML and the plain-text part. Values are stored straight on the models, without the checks the forms run, because values saved before those checks existed still reach emails.
 */
class EmailValuesTest extends TestCase
{
    use BuildsEmailExamples, RefreshDatabase;

    private const NAME = '[Sign in](https://evil.example) ![x](https://evil.example/t.gif) *Sam* _Lee_';

    private const TEXT = '- [here](https://evil.example)';

    private const EDIT_WORD = 'Zebra';

    /** The sent HTML part has its styles inlined, so tags carry a style attribute. */
    private const BOLD_DATE = '#<strong\b[^>]*>[A-Z][a-z]+day, [A-Z][a-z]+ \d+</strong>#';

    /** Catalog variables that hold a person's name. */
    private const NAME_VARIABLES = ['first_name', 'invited_by', 'admin', 'coordinator', 'changed_by', 'shadow', 'taker', 'releaser', 'names', 'leads'];

    /** Catalog variables that hold an area name, a title, a reason or the organization's name, or start with one. */
    private const TEXT_VARIABLES = ['organization', 'area', 'title', 'was_title', 'reason', 'reasons', 'slots', 'shift_link'];

    public function test_every_email_shows_hostile_values_as_typed(): void
    {
        $this->assertHostileValuesReadAsTyped([]);
    }

    public function test_every_edited_email_shows_hostile_values_as_typed(): void
    {
        // Every line edited to its default plus one word, so the edited path fills in every value of every email and version.
        $edits = collect(Arr::dot(Lang::get('emails', [], 'en')))->filter(fn ($text) => is_string($text))->map(fn (string $text) => EmailText::normalize($text).' '.self::EDIT_WORD)->all();
        DB::table('email_wordings')->insert(collect($edits)->map(fn (string $text, string $key) => ['key' => $key, 'text' => $text, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()])->values()->all());
        EmailWording::forgetCache();

        $this->assertHostileValuesReadAsTyped($edits);
    }

    /**
     * Sends every email with the hostile values, and previews every version of every catalog email with them, checking that no value became markup and that each reads as typed in both parts. With `$edits`, the stored edits are used in the sent emails and the same edits in the preview, and each email has to show the edit.
     *
     * @param  array<string, string>  $edits
     */
    private function assertHostileValuesReadAsTyped(array $edits): void
    {
        $examples = $this->emailExamples(self::NAME, self::TEXT);
        $person = User::query()->firstWhere('email', 'sam@example.org');
        $forms = [self::NAME, self::TEXT, $person->firstName(), $person->shortName()];
        $seen = array_fill_keys($forms, 0);
        $boldDates = 0;

        foreach ($examples as $label => [$notification, $user]) {
            $mail = $this->sentMail($notification, $user);
            $this->assertNoMarkupFromValues($mail['html'], $label, [config('app.url'), 'https://example.org/']);
            $boldDates += preg_match_all(self::BOLD_DATE, $mail['html']);

            $visible = $this->visibleText($mail['html']);
            if ($edits !== []) {
                $this->assertStringContainsString(self::EDIT_WORD, $mail['text'], "{$label}: the edits weren't used.");
            }
            $this->assertDoesNotMatchRegularExpression('/&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $mail['text'], "{$label}: the text part shows an entity.");
            $this->assertStringNotContainsString('\\[', $mail['text'], "{$label}: the text part shows a backslash escape.");

            foreach ($forms as $form) {
                // Each value shows as many times, exactly as typed, in what the HTML part displays and in the text part.
                $this->assertSame(substr_count($visible, $form), substr_count($mail['text'], $form), "{$label}: [{$form}] doesn't read the same in both parts.");
                $seen[$form] += substr_count($mail['text'], $form);
            }
        }

        foreach ($seen as $form => $count) {
            $this->assertGreaterThan(0, $count, "No email showed [{$form}].");
        }
        // The bold the code builds (the date in the "no answer yet" email) is still bold.
        $this->assertGreaterThan(0, $boldDates);

        // And the editor's preview, in every version of every email.
        $previewed = '';
        foreach (EmailCatalog::all() as $email) {
            $hostile = $this->withHostileExamples($email);

            foreach (array_keys($hostile->versions) as $version) {
                $blocks = EmailPreview::render($hostile, $version, $edits)['blocks'];
                if ($edits !== []) {
                    $this->assertStringContainsString(self::EDIT_WORD, implode(' ', array_map(fn (array $block) => $block['html']->toHtml(), $blocks)), "preview of {$email->key}: {$version} didn't use the edits.");
                }

                foreach ($blocks as $block) {
                    $html = $block['html']->toHtml();
                    $this->assertNoMarkupFromValues($html, "preview of {$email->key}: {$version}", [EmailPreview::URL]);
                    $this->assertStringNotContainsString('&amp;#', $html, "preview of {$email->key}: {$version} is escaped twice.");
                    $previewed .= $this->visibleText($html)."\n";
                    $boldDates += preg_match_all(self::BOLD_DATE, $html);
                }
            }
        }

        $this->assertStringContainsString(self::NAME, $previewed);
        $this->assertStringContainsString(self::TEXT, $previewed);
        $this->assertGreaterThan(1, $boldDates);
    }

    public function test_real_names_read_as_typed(): void
    {
        $this->emailExamples();
        $names = ["Sean O'Brien", 'Nguyễn Thị Lan', 'Mary_Ann Lee', 'Sam & Alex', '- Sam'];
        $person = User::query()->firstWhere('email', 'sam@example.org');

        // A name with only a list mark and no other Markdown, at the start of a line: the line can't stay the plain string, or Markdown would make it a list item.
        $lead = User::query()->firstWhere('email', 'chris@example.org');
        $leadShift = Shift::query()->with('area')->whereNull('title')->orderBy('starts_at')->firstOrFail();
        $leadMail = $this->sentMail(new ShadowSignedUpLeadNotification($leadShift, '- Sam', []), $lead);
        $this->assertStringContainsString('- Sam signed up to shadow Front desk', $leadMail['text']);
        $this->assertStringContainsString('- Sam signed up to shadow Front desk', $this->visibleText($leadMail['html']));
        $this->assertDoesNotMatchRegularExpression('/<(ul|ol|li)\b/i', $leadMail['html']);

        $person->forceFill(['name' => "Mary_Ann O'Brien"])->saveQuietly();
        $shift = Shift::query()->whereHas('assignments', fn ($query) => $query->where('user_id', $person->id))->whereNotNull('title')->firstOrFail();
        $shift->forceFill(['title' => 'Sam & Alex\'s_open house'])->saveQuietly();
        $shift->area->forceFill(['name' => 'Nguyễn\'s *bench*'])->saveQuietly();
        $assignment = Assignment::query()->where('shift_id', $shift->id)->where('user_id', $person->id)->firstOrFail();

        $mail = $this->sentMail(new ShiftReminderNotification($shift->fresh('area'), $assignment, $names, $names, null), $person->fresh());
        $visible = $this->visibleText($mail['html']);

        foreach ([...$names, 'Mary_Ann', 'Sam & Alex\'s_open house', 'Nguyễn\'s *bench*'] as $typed) {
            $this->assertStringContainsString($typed, $mail['text'], "{$typed} in the text part");
            $this->assertStringContainsString($typed, $visible, "{$typed} in the HTML part");
        }
        $this->assertStringContainsString('Sam &amp; Alex', $mail['html']);
        $this->assertDoesNotMatchRegularExpression('/<em\b/i', $mail['html']);
        $this->assertStringNotContainsString('&amp;amp;', $mail['html']);
        $this->assertDoesNotMatchRegularExpression('/&(#\d+|#x[0-9a-f]+|[a-z]+);/i', $mail['text']);
    }

    public function test_the_app_name_is_plain_again_after_a_failed_send(): void
    {
        Organization::factory()->create(['name' => 'Example Makers']);
        $sam = User::factory()->create();
        $notification = new PasswordChangedNotification;

        event(new NotificationSending($sam, $notification, 'mail'));
        $this->assertInstanceOf(Htmlable::class, config('app.name'));

        event(new NotificationFailed($sam, $notification, 'mail', ['exception' => new RuntimeException('The mail server refused it.')]));
        $this->assertSame('Example Makers', config('app.name'));

        // And after a send that worked.
        event(new NotificationSending($sam, $notification, 'mail'));
        event(new NotificationSent($sam, $notification, 'mail'));
        $this->assertSame('Example Makers', config('app.name'));
    }

    /**
     * No link, image, emphasis, list or other formatting came from a value: every link goes to one of the code's own addresses, and the only bold is the code's own (a date).
     *
     * @param  list<string>  $addresses
     */
    private function assertNoMarkupFromValues(string $html, string $label, array $addresses): void
    {
        preg_match_all('/<a\b[^>]*>/i', $html, $links);

        foreach ($links[0] as $link) {
            $this->assertMatchesRegularExpression('/\bhref="([^"]*)"/', $link, "{$label}: a link with no address.");
            preg_match('/\bhref="([^"]*)"/', $link, $href);
            $ours = array_filter($addresses, fn (string $address) => str_starts_with(html_entity_decode($href[1]), $address)) !== [];
            $this->assertTrue($ours, "{$label}: a link to {$href[1]}.");
        }

        foreach (['img', 'em', 'ul', 'ol', 'li', 'hr', 'blockquote', 'code', 'pre', 'del', 'h2', 'h3', 'h4'] as $tag) {
            $this->assertDoesNotMatchRegularExpression('/<'.$tag.'\b/i', $html, "{$label}: has <{$tag}>.");
        }

        preg_match_all('#<strong\b[^>]*>(.*?)</strong>#s', $html, $bold);
        foreach ($bold[1] as $text) {
            $this->assertMatchesRegularExpression('/^[A-Z][a-z]+day, [A-Z][a-z]+ \d+$/', $text, "{$label}: bold text that isn't a date.");
        }
    }

    /** What a mail app shows from the HTML part's body: its text, with entities turned back into characters. */
    private function visibleText(string $html): string
    {
        $body = preg_match('#<body[^>]*>(.*)</body>#s', $html, $match) === 1 ? $match[1] : $html;

        return html_entity_decode(strip_tags($body), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    /** The email with every name and every area name, title, reason and organization name in its examples (the email's, each version's and each line's own) set to the hostile values. */
    private function withHostileExamples(CatalogEmail $email): CatalogEmail
    {
        $hostile = function (array $examples): array {
            foreach ($examples as $name => $value) {
                $examples[$name] = match (true) {
                    in_array($name, self::NAME_VARIABLES, true) => self::NAME,
                    in_array($name, self::TEXT_VARIABLES, true) => self::TEXT,
                    default => $value,
                };
            }

            return $examples;
        };

        $lines = array_map(fn (array $line) => ['examples' => $hostile($line['examples'] ?? [])] + $line, $email->lines);
        $versions = array_map(fn (array $version) => ['examples' => $hostile($version['examples'])] + $version, $email->versions);

        return new CatalogEmail($email->key, $email->title, $email->group, $email->when, $lines, $hostile($email->examples), $versions);
    }
}
