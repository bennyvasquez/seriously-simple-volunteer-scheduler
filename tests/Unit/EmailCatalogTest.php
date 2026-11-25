<?php

namespace Tests\Unit;

use App\Emails\EmailCatalog;
use App\Models\Organization;
use App\Notifications\EmailText;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\Str;
use Tests\TestCase;

class EmailCatalogTest extends TestCase
{
    use RefreshDatabase;

    private const GROUPS = [
        'To volunteers about their own shifts',
        'Asking for cover',
        'Shadowing',
        'To shift leads and coordinators',
        'Logging in and accounts',
        'Every email',
    ];

    /** @return list<string> */
    private function wordingKeys(): array
    {
        return array_keys(Arr::dot(Lang::get('emails', [], 'en')));
    }

    /** @return list<string> */
    private function catalogLineKeys(): array
    {
        $keys = [];
        foreach (EmailCatalog::all() as $email) {
            array_push($keys, ...$email->lineKeys());
        }

        return $keys;
    }

    public function test_every_line_in_the_wording_file_is_in_the_catalog_once(): void
    {
        $counts = array_count_values($this->catalogLineKeys());

        foreach ($this->wordingKeys() as $key) {
            $this->assertSame(1, $counts[$key] ?? 0, "[{$key}] should be in the catalog exactly once.");
        }

        foreach ($counts as $key => $count) {
            $this->assertSame(1, $count, "[{$key}] is in the catalog more than once.");
        }
    }

    public function test_every_catalog_line_exists_in_the_wording_file(): void
    {
        $wording = $this->wordingKeys();

        foreach ($this->catalogLineKeys() as $key) {
            $this->assertContains($key, $wording, "[{$key}] is in the catalog but not in lang/en/emails.php.");
        }
    }

    public function test_every_variable_has_an_example(): void
    {
        foreach (EmailCatalog::all() as $email) {
            foreach ($email->lines as $line) {
                foreach (EmailText::allowedVariables($line['key']) as $variable) {
                    $this->assertArrayHasKey($variable, $email->examples, "{{$variable}} in [{$line['key']}] needs an example in the {$email->title} email.");
                    $this->assertNotSame('', $email->examples[$variable]);
                }
            }
        }
    }

    public function test_every_version_names_real_lines(): void
    {
        $all = $this->catalogLineKeys();
        $inside = [];
        foreach (EmailCatalog::all() as $email) {
            foreach ($email->lines as $line) {
                if ($line['inside']) {
                    $inside[] = $line['key'];
                }
            }
        }

        foreach (EmailCatalog::all() as $email) {
            $this->assertNotEmpty($email->versions, "{$email->title} needs at least one version.");

            foreach ($email->versions as $name => $version) {
                $this->assertNotEmpty($version['lines'], "{$email->title}, version {$name}, shows no lines.");
                // A version may name another email's line when that email's lines are added to this one (the back on lines of a cancelled email).
                $this->assertSame([], array_diff($version['lines'], $all), "{$email->title}, version {$name}, names a line that isn't in the catalog.");
                $this->assertSame([], array_intersect($version['lines'], $inside), "{$email->title}, version {$name}, shows a line that's only used inside other lines.");
                $this->assertSame(array_values(array_unique($version['lines'])), $version['lines'], "{$email->title}, version {$name}, repeats a line.");
                $this->assertSame([], array_diff(array_keys($version['examples']), array_keys($email->examples)), "{$email->title}, version {$name}, overrides a variable the email doesn't have.");

                foreach ($version['lines'] as $key) {
                    foreach (EmailText::allowedVariables($key) as $variable) {
                        $this->assertArrayHasKey($variable, $email->examples, "{{$variable}} in [{$key}] needs an example in the {$email->title} email.");
                    }
                }
            }
        }
    }

    public function test_a_lines_own_examples_are_for_variables_that_line_uses(): void
    {
        $found = false;

        foreach (EmailCatalog::all() as $email) {
            foreach ($email->lines as $line) {
                $found = $found || $line['examples'] !== [];
                $this->assertSame([], array_diff(array_keys($line['examples']), EmailText::allowedVariables($line['key'])), "[{$line['key']}] has an example for a word it doesn't use.");
            }
        }

        $this->assertTrue($found, 'No line has its own examples, so this check isn\'t looking at anything.');
    }

    public function test_every_email_class_is_in_the_catalog(): void
    {
        $classes = [];
        foreach (glob(app_path('Notifications/*Notification.php')) as $file) {
            $classes[] = Str::snake(Str::beforeLast(basename($file, '.php'), 'Notification'));
        }

        $keys = array_map(fn ($email) => $email->key, EmailCatalog::all());

        $this->assertCount(32, $classes);

        foreach ($classes as $class) {
            $this->assertContains($class, $keys, "{$class} isn't in the catalog.");
        }

        $this->assertContains('every_email', $keys);
        $this->assertCount(33, $keys);
        $this->assertSame($keys, array_values(array_unique($keys)));
    }

    public function test_each_email_has_a_known_group_a_title_and_a_one_line_sentence(): void
    {
        foreach (EmailCatalog::all() as $email) {
            $this->assertContains($email->group, self::GROUPS);
            $this->assertNotSame('', $email->title);
            $this->assertNotSame('', $email->when);
            $this->assertNotEmpty($email->lines);

            foreach ([$email->title, $email->when, ...array_column($email->lines, 'label'), ...array_filter(array_column($email->lines, 'when'))] as $text) {
                $this->assertDoesNotMatchRegularExpression('/[—–\n]/u', $text, "Copy in {$email->title} has a dash or a line break: {$text}");
            }
        }
    }

    public function test_find_returns_one_email_or_null(): void
    {
        $this->assertSame('Shift reminder', EmailCatalog::find('shift_reminder')?->title);
        $this->assertNull(EmailCatalog::find('not_an_email'));
        $this->assertNotNull(EmailCatalog::find('every_email'));
    }

    public function test_the_reminder_sentence_reads_the_hours_from_settings(): void
    {
        Organization::factory()->create(['reminder_hours' => 12]);
        $this->assertStringContainsString('12 hours', EmailCatalog::find('shift_reminder')->when);

        Organization::query()->update(['reminder_hours' => 1]);
        $this->assertStringContainsString('1 hour before', EmailCatalog::find('shift_reminder')->when);

        Organization::query()->update(['reminder_hours' => null]);
        $when = EmailCatalog::find('shift_reminder')->when;
        $this->assertStringContainsString('off', strtolower($when));
        $this->assertStringContainsString('Settings', $when);
    }
}
