<?php

namespace App\Notifications;

use App\Actions\Emails\SaveEmailWording;
use App\Models\EmailWording;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\HtmlString;
use Illuminate\Translation\Translator;
use RuntimeException;

/**
 * The four lines Laravel's mail layout adds to every email (the "Every email" page in the editor): the heading of an email with no greeting, the sign-off, the line under each button and the end of the footer. Laravel reads them as JSON translation lines ("Hello!", "Regards,", ...). They aren't in lang/en.json, so the translator asks the callback that register() installs, which answers with the organization's edit when there is one and otherwise lets Laravel use its own line. No Laravel view is published or copied, and an email reads exactly as before until someone edits one of these lines.
 *
 * The edit is read when the email is rendered, from the same per-request copy every other edited line uses (EmailWording::edits(), cleared on every save), so a line put back to the default is never sent again. An edit is escaped the way EmailText escapes any edited line, because Laravel's view echoes these lines without escaping them (`@lang`). In the button line, {button} becomes Laravel's :actionText, and a colon typed in the edit is made an entity, so typed text can't name one of Laravel's placeholders. The address after that line is always Laravel's own.
 *
 * The footer is different: the HTML part puts it through Blade's escaping and then Markdown, so entities would show as typed ("&#42;"), and the plain-text part strips anything that looks like a tag. It's sent as typed instead, which is safe because an edit can't hold the marks Markdown reads there or angle brackets (FOOTER_MARKS), or anything shaped like an HTML entity (`&#58;`, `&lt;`), since the plain-text part decodes entities after stripping tags and an entity could spell out a web address or a link. Saving refuses all of that, and when an email is sent the stored footer gets the same checks a save runs (SaveEmailWording::problemWith()): one that fails them anyway (put in by hand) is reported and Laravel's line goes out.
 *
 * These four keys are for email only. Laravel's mail views echo them raw, so an edit comes back escaped as HTML entities; a screen that showed `{{ __('Hello!') }}` would show those entities. No screen uses them (a test checks).
 */
final class LaravelMailLines
{
    /** Laravel's JSON line, as its mail views ask for it, and the key of the editor's line for it in lang/en/emails.php. */
    public const KEYS = [
        'Hello!' => 'every_email.heading',
        'Regards,' => 'every_email.sign_off',
        "If you're having trouble clicking the \":actionText\" button, copy and paste the URL below\ninto your web browser:" => 'every_email.button_trouble',
        'All rights reserved.' => 'every_email.footer',
    ];

    public const FOOTER = 'every_email.footer';

    /** What the footer can't hold: Markdown's link, emphasis, code, strikethrough and table marks, the backslash that escapes them, and the angle brackets the plain-text part strips as tags. Everything else in it is escaped by Blade. */
    public const FOOTER_MARKS = ['[', ']', '*', '_', '`', '~', '|', '\\', '<', '>'];

    /** Lets the translator ask for these four lines. Any other missing line is left to Laravel, which shows its key, as before. */
    public static function register(Translator $translator): void
    {
        $translator->handleMissingKeysUsing(fn (string $key) => self::line($key));
    }

    /** The organization's edit of one of Laravel's lines, ready for Laravel's view, or null to send Laravel's own. */
    public static function line(string $laravelKey): ?string
    {
        $key = self::KEYS[$laravelKey] ?? null;

        if ($key === null || ! array_key_exists($key, EmailWording::edits())) {
            return null;
        }

        if ($key === self::FOOTER) {
            $edit = EmailText::normalize(EmailWording::edits()[$key]);

            if (($problem = app(SaveEmailWording::class)->problemWith($key, $edit)) !== null) {
                report(new RuntimeException("The edited email wording [emails.{$key}] can't be used ({$problem}), so the default line was sent instead."));

                return null;
            }

            return $edit;
        }

        // EmailText checks the edit and escapes it (an edit it can't use is reported and comes back as the default). The {button} it fills in, used only by the button line, is a marker, swapped for Laravel's placeholder once every typed colon is an entity.
        $line = EmailText::line($key, ['button' => new HtmlString('{button}')]);
        $html = $line instanceof Htmlable ? $line->toHtml() : $line;

        return str_replace([':', '{button}'], ['&#58;', ':actionText'], $html);
    }

    /** Something shaped like an HTML entity: `&#58;`, `&#x3a;`, `&lt;`. */
    private const ENTITY = '/&(#\d+|#x[0-9a-f]+|[a-z][a-z0-9]*);/i';

    /** The footer's own refusals (the rest of a save's checks run too): anything shaped like an entity, and the marks it can't hold or a web address, in the text as typed or with its entities turned back into characters. Null when it has none. */
    public static function footerProblem(string $text): ?string
    {
        if (preg_match(self::ENTITY, $text) === 1) {
            return 'The footer can\'t contain codes that start with & and end with ;.';
        }

        $decoded = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        if (SaveEmailWording::hasWebAddress($decoded)) {
            return SaveEmailWording::WEB_ADDRESS;
        }

        $marks = self::FOOTER_MARKS;

        foreach ($marks as $mark) {
            if (str_contains($text, $mark) || str_contains($decoded, $mark)) {
                $last = array_pop($marks);

                return 'The footer can\'t use '.implode(' ', $marks).' or '.$last.', because emails would turn them into formatting or links.';
            }
        }

        return null;
    }
}
