<?php

namespace App\Actions\Emails;

use App\Models\ChangeLogEntry;
use App\Models\EmailWording;
use App\Models\User;
use App\Notifications\EmailText;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves edited email lines (spec section 10). `$lines` maps a line key ("login_link.intro") to its new text; only null puts the line back to the default. Every line is checked before anything is stored: the key has to be a line in lang/en/emails.php, the text can't be empty, only spaces or longer than MAX characters, it can't hold a web address ("://" or "www.", since mail apps turn bare addresses into links themselves), every {variable} has to be one the default line has, written exactly, and a link variable (one ending in `_link`) or {note} the default has must stay. A refusal is a ValidationException keyed by line key, and nothing is saved. Text is stored with Unicode format characters (bidi overrides, zero-width characters) removed and its whitespace made single spaces, which is how it's sent.
 *
 * Two first edits of the same line at once can deadlock or collide on the unique key: the transaction is tried up to three times, and a collision that's left is refused with a plain message rather than an error page.
 *
 * Only lines whose text changed are written and logged, so a save never touches a line someone else changed meanwhile unless this save changes it too. Text that matches the default is the default: saving it puts the line back rather than storing a copy, so a later improvement to the default still reaches that line. Each changed line gets one change log entry, `email_wording.changed` or `email_wording.reset`, with the text before and after (the default's text when the line wasn't edited) and the line and email keys in details. The editor also passes the text each line had when its page loaded, and a line someone else saved since is refused (STALE) rather than overwritten.
 */
class SaveEmailWording
{
    public const MAX = 1000;

    /** The refusal for a line someone else saved after the editor's page loaded. */
    public const STALE = 'Someone else changed this line while you were editing. Here\'s their version; make your change again.';

    /**
     * @param  array<string, ?string>  $lines
     * @param  array<string, string>  $expected  Optional: for some lines, the text the person started from (the editor page's text as it loaded). If a line's stored text (or the default, when it has none) no longer matches, someone else saved it meanwhile: the whole save is refused with STALE for that line, unless they saved exactly what this save would. Checked under the row lock, so two saves of one line at once can't both pass.
     */
    public function handle(User $actor, array $lines, array $expected = []): void
    {
        $wanted = $this->check($lines);
        $current = null;

        try {
            DB::transaction(function () use ($actor, $wanted, $expected, &$current) {
                $this->store($actor, $wanted, $expected, $current);
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages([(string) $current => 'Someone else just changed this line. Check it and try again.']);
        } finally {
            EmailWording::forgetCache();
        }
    }

    /**
     * @param  array<string, ?string>  $wanted
     * @param  array<string, string>  $expected
     */
    private function store(User $actor, array $wanted, array $expected, ?string &$current): void
    {
        ksort($wanted);
        $rows = EmailWording::query()->whereIn('key', array_keys($wanted))->orderBy('key')->lockForUpdate()->get()->keyBy('key');
        $stale = [];

        foreach ($wanted as $key => $text) {
            if (! array_key_exists($key, $expected)) {
                continue;
            }

            $default = EmailText::defaultText($key);
            $stored = EmailText::normalize($rows->get($key)?->text ?? $default);

            if ($stored !== EmailText::normalize($expected[$key]) && $stored !== EmailText::normalize($text ?? $default)) {
                $stale[$key] = self::STALE;
            }
        }

        // Thrown inside the transaction, so nothing from this save is stored.
        if ($stale !== []) {
            throw ValidationException::withMessages($stale);
        }

        foreach ($wanted as $key => $text) {
            $current = $key;
            $default = EmailText::defaultText($key);
            $row = $rows->get($key);
            $old = $row?->text ?? $default;
            $new = $text ?? $default;

            if ($old === $new && ($row === null) === ($text === null)) {
                continue;
            }

            $details = ['key' => $key, 'email' => explode('.', $key)[0]];
            $changes = ['text' => ['old' => $old, 'new' => $new]];

            if ($text === null) {
                ChangeLogEntry::record($actor, 'email_wording.reset', $row, $details, $changes);
                $row->delete();

                continue;
            }

            $row ??= new EmailWording(['key' => $key]);
            $row->text = $text;
            $row->updated_by = $actor->id;
            $row->save();

            ChangeLogEntry::record($actor, 'email_wording.changed', $row, $details, $changes);
        }
    }

    /**
     * Checks every line and returns the text to store for each, null meaning the default. It stores nothing, so the editor's preview uses it too, to refuse what a save would refuse. Throws a ValidationException keyed by line key.
     *
     * @param  array<string, ?string>  $lines
     * @return array<string, ?string>
     */
    public function check(array $lines): array
    {
        $errors = [];
        $wanted = [];

        foreach ($lines as $key => $text) {
            $key = (string) $key;

            if (! EmailText::isLine($key)) {
                $errors[$key] = 'There\'s no email line called '.$key.'.';

                continue;
            }

            if ($text === null) {
                $wanted[$key] = null;

                continue;
            }

            $text = EmailText::normalize($text);
            $error = $this->problemWith($key, $text);

            if ($error !== null) {
                $errors[$key] = $error;

                continue;
            }

            $wanted[$key] = $text === EmailText::normalize(EmailText::defaultText($key)) ? null : $text;
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return $wanted;
    }

    private function problemWith(string $key, string $text): ?string
    {
        if ($text === '') {
            return 'Type the wording, or use Back to the default.';
        }

        if (mb_strlen($text) > self::MAX) {
            return 'Keep this line to '.self::MAX.' characters or fewer. It has '.mb_strlen($text).'.';
        }

        if (stripos($text, '://') !== false || stripos($text, 'www.') !== false) {
            return 'Emails can\'t have web addresses typed into them. Links come from SSVS.';
        }

        $allowed = EmailText::allowedVariables($key);
        preg_match_all('/\{[^{}]*\}/', $text, $matches);
        $unknown = array_values(array_unique(array_filter($matches[0], fn (string $braced) => ! in_array(substr($braced, 1, -1), $allowed, true))));

        if ($unknown !== []) {
            $start = count($unknown) === 1 ? $unknown[0].' isn\'t a word this line can use.' : self::list($unknown).' aren\'t words this line can use.';
            $close = array_map(fn (string $braced) => self::closest(substr($braced, 1, -1), $allowed), $unknown);

            if (! in_array(null, $close, true)) {
                return $start.' Did you mean '.self::list(array_values(array_unique(array_map(fn (string $name) => '{'.$name.'}', $close)))).'?';
            }

            $words = $allowed === [] ? 'This line has no words in braces you can use.' : 'The words in braces this line can use are '.self::list(array_map(fn (string $name) => '{'.$name.'}', $allowed)).'.';

            return $start.' '.$words;
        }

        $missing = array_values(array_filter(EmailText::requiredVariables($key), fn (string $name) => ! str_contains($text, '{'.$name.'}')));

        if (in_array('note', $missing, true)) {
            return 'Keep {note} in this line: it\'s the note the volunteer wrote, and without it they\'d never know it wasn\'t sent.';
        }

        if ($missing !== []) {
            return 'Keep '.self::list(array_map(fn (string $name) => '{'.$name.'}', $missing)).' in this line: it\'s the link people use from this email.';
        }

        return null;
    }

    /**
     * The allowed word a mistyped one most likely meant: one that starts with it or it starts with ({emails_page} for {emails_page_link}), or one a couple of letters away ({firstname} for {first_name}). Null when nothing is that close.
     *
     * @param  list<string>  $allowed
     */
    private static function closest(string $typed, array $allowed): ?string
    {
        $typed = strtolower(trim($typed));
        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($allowed as $name) {
            $distance = $typed !== '' && (str_starts_with($name, $typed) || str_starts_with($typed, $name)) ? 0 : levenshtein($typed, $name);

            if ($distance < $bestDistance && $distance <= max(2, intdiv(strlen($name), 4))) {
                $best = $name;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** @param list<string> $items "{a}", "{a} and {b}", "{a}, {b} and {c}". */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
