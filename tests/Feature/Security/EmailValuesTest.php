<?php

namespace Tests\Feature\Security;

use App\Emails\CatalogEmail;
use App\Emails\EmailCatalog;
use App\Emails\EmailPreview;
use App\Models\Assignment;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftReminderNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    /** The sent HTML part has its styles inlined, so tags carry a style attribute. */
    private const BOLD_DATE = '#<strong\b[^>]*>[A-Z][a-z]+day, [A-Z][a-z]+ \d+</strong>#';

    /** Catalog variables that hold a person's name. */
    private const NAME_VARIABLES = ['first_name', 'invited_by', 'admin', 'coordinator', 'changed_by', 'shadow', 'taker', 'releaser', 'names', 'leads'];

    /** Catalog variables that hold an area name, a title, a reason or the organization's name, or start with one. */
    private const TEXT_VARIABLES = ['organization', 'area', 'title', 'was_title', 'reason', 'reasons', 'slots', 'shift_link'];

    public function test_every_email_shows_hostile_values_as_typed(): void
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
                foreach (EmailPreview::render($hostile, $version, [])['blocks'] as $block) {
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
        $names = ["Sean O'Brien", 'Nguyễn Thị Lan', 'Mary_Ann Lee', 'Sam & Alex'];
        $person = User::query()->firstWhere('email', 'sam@example.org');
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
