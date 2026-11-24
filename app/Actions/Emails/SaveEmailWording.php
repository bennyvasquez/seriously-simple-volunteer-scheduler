<?php

namespace App\Actions\Emails;

use App\Models\ChangeLogEntry;
use App\Models\EmailWording;
use App\Models\User;
use App\Notifications\EmailText;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Saves edited email lines (spec section 10). `$lines` maps a line key ("login_link.intro") to its new text; null or an empty string puts the line back to the default. Every line is checked before anything is stored: the key has to be a line in lang/en/emails.php, the text can't be only spaces or longer than MAX characters, every {variable} has to be one the default line has, written exactly, and a link variable the default has (one ending in `_link`) has to stay. A refusal is a ValidationException keyed by line key, and nothing is saved. Text is stored with its whitespace made single spaces, which is how it's sent.
 *
 * Only lines whose text changed are written and logged, so a save never touches a line someone else changed meanwhile unless this save changes it too. Text that matches the default is the default: saving it puts the line back rather than storing a copy, so a later improvement to the default still reaches that line. Each changed line gets one change log entry, `email_wording.changed` or `email_wording.reset`, with the text before and after (the default's text when the line wasn't edited) and the line and email keys in details.
 */
class SaveEmailWording
{
    public const MAX = 1000;

    /** @param array<string, ?string> $lines */
    public function handle(User $actor, array $lines): void
    {
        $wanted = $this->check($lines);

        DB::transaction(function () use ($actor, $wanted) {
            ksort($wanted);
            $rows = EmailWording::query()->whereIn('key', array_keys($wanted))->orderBy('key')->lockForUpdate()->get()->keyBy('key');

            foreach ($wanted as $key => $text) {
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
        });

        EmailWording::forgetCache();
    }

    /**
     * Checks every line and returns the text to store for each, null meaning the default.
     *
     * @param  array<string, ?string>  $lines
     * @return array<string, ?string>
     */
    private function check(array $lines): array
    {
        $errors = [];
        $wanted = [];

        foreach ($lines as $key => $text) {
            $key = (string) $key;

            if (! EmailText::isLine($key)) {
                $errors[$key] = 'There\'s no email line called '.$key.'.';

                continue;
            }

            if ($text === null || $text === '') {
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
            return 'Write something for this line, or put it back to the default.';
        }

        if (mb_strlen($text) > self::MAX) {
            return 'Keep this line to '.self::MAX.' characters or fewer. It has '.mb_strlen($text).'.';
        }

        $allowed = EmailText::allowedVariables($key);
        preg_match_all('/\{[^{}]*\}/', $text, $matches);
        $unknown = array_values(array_unique(array_filter($matches[0], fn (string $braced) => ! in_array(substr($braced, 1, -1), $allowed, true))));

        if ($unknown !== []) {
            $words = $allowed === [] ? 'This line has no words in braces you can use.' : 'The words in braces this line can use are '.self::list(array_map(fn (string $name) => '{'.$name.'}', $allowed)).'.';

            return 'This line can\'t use '.self::list($unknown).'. '.$words;
        }

        $missing = array_values(array_filter(EmailText::requiredVariables($key), fn (string $name) => ! str_contains($text, '{'.$name.'}')));

        if ($missing !== []) {
            return 'Keep '.self::list(array_map(fn (string $name) => '{'.$name.'}', $missing)).' in this line: it\'s the link people use from this email.';
        }

        return null;
    }

    /** @param list<string> $items "{a}", "{a} and {b}", "{a}, {b} and {c}". */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items).' and '.$last;
    }
}
