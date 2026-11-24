<?php

namespace App\Http\Controllers;

use App\Actions\Emails\SaveEmailWording;
use App\Emails\CatalogEmail;
use App\Emails\EmailCatalog;
use App\Emails\EmailPreview;
use App\Models\EmailWording;
use App\Notifications\EmailText;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\MessageBag;
use Illuminate\Support\ViewErrorBag;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * The email editor (wireframes 61 to 63): the list of emails, one email's lines with when each shows, saving the lines someone changed, putting one line back to the default, and the preview. The routes are for active coordinators and admins only (`can:manage-emails`), and saving, resetting and previewing are rate limited per person.
 *
 * The form sends every line's box (`lines`) and, in hidden inputs, each line's text as the page loaded it (`loaded`). Only the lines someone changed go to SaveEmailWording, so a save never touches another line. A changed line whose stored text no longer matches what the page loaded means someone else saved it meanwhile: the whole submission is refused, and the box shows their version.
 */
class EmailWordingController extends Controller
{
    private const STALE = 'Someone else changed this line while you were editing. Here\'s their version; make your change again.';

    public function index(): View
    {
        $edits = EmailWording::edits();
        $groups = [];

        foreach (EmailCatalog::all() as $email) {
            $groups[$email->group][] = [
                'email' => $email,
                'summary' => self::firstSentence($email->when),
                'edited' => array_intersect_key($edits, array_flip($email->lineKeys())) !== [],
            ];
        }

        return view('emails.index', ['groups' => $groups]);
    }

    public function edit(Request $request, string $email): View
    {
        $catalog = $this->find($email);

        return $this->page($request, $catalog, (array) $request->old('lines', []), (array) $request->old('loaded', []), null);
    }

    public function update(Request $request, string $email, SaveEmailWording $save): RedirectResponse
    {
        $catalog = $this->find($email);
        [$lines, $loaded] = $this->input($request, $catalog);
        $edits = EmailWording::edits();
        $changed = [];
        $stale = [];

        foreach ($lines as $key => $text) {
            $was = EmailText::normalize($loaded[$key] ?? '');

            if (EmailText::normalize($text) === $was) {
                continue;
            }

            $current = $edits[$key] ?? EmailText::defaultText($key);

            // Someone else saved this line since the page loaded, unless they saved exactly what this person typed.
            if (EmailText::normalize($current) !== $was) {
                if (EmailText::normalize($current) !== EmailText::normalize($text)) {
                    $stale[$key] = $current;
                }

                continue;
            }

            $changed[$key] = $text;
        }

        $back = redirect()->route('emails.edit', $catalog->key);

        if ($stale !== []) {
            return $back->withInput(['lines' => array_merge($lines, $stale), 'loaded' => array_merge($loaded, $stale)])
                ->withErrors(array_combine(array_map(fn (string $key) => 'lines.'.$key, array_keys($stale)), array_fill(0, count($stale), self::STALE)));
        }

        if ($changed === []) {
            return $back->with('status', 'Nothing changed, so there was nothing to save.');
        }

        try {
            $save->handle($request->user(), $changed);
        } catch (ValidationException $e) {
            return $back->withInput(['lines' => $lines, 'loaded' => $loaded])->withErrors($this->lineErrors($e));
        }

        return $back->with('status', 'Saved. Emails sent from now on use the new wording.');
    }

    public function reset(Request $request, string $email, string $line, SaveEmailWording $save): RedirectResponse
    {
        $catalog = $this->find($email);
        abort_unless(in_array($line, $this->editableKeys($catalog), true), 404);

        $save->handle($request->user(), [$line => null]);

        return redirect()->route('emails.edit', $catalog->key)->with('status', 'That line is back to the default wording.');
    }

    /** Shows the edit page again with the typed text in the boxes and the preview of the chosen version. Nothing is saved, logged or sent. */
    public function preview(Request $request, string $email, SaveEmailWording $save): View
    {
        $catalog = $this->find($email);
        [$lines, $loaded] = $this->input($request, $catalog);
        $request->validate(['version' => ['nullable', 'string']]);

        $edits = EmailWording::edits();
        $typed = [];

        foreach ($lines as $key => $text) {
            if (EmailText::normalize($text) !== EmailText::normalize($edits[$key] ?? EmailText::defaultText($key))) {
                $typed[$key] = $text;
            }
        }

        try {
            foreach ($save->check($typed) as $key => $text) {
                if ($text === null) {
                    unset($edits[$key]);
                } else {
                    $edits[$key] = $text;
                }
            }
        } catch (ValidationException $e) {
            return $this->page($request, $catalog, $lines, $loaded, $this->version($catalog, $request->input('version')), null, $this->lineErrors($e));
        }

        return $this->page($request, $catalog, $lines, $loaded, $this->version($catalog, $request->input('version')), $edits);
    }

    /**
     * @param  array<string, string>  $typed  What's in each box, when it isn't the current text.
     * @param  array<string, string>  $loaded  Each line's text as the page first loaded it.
     * @param  ?string  $version  The version to preview, or null when Preview wasn't pressed.
     * @param  ?array<string, string>  $edits  The edits the preview uses, or null to use the stored ones.
     * @param  ?array<string, string>  $errors  Refusals keyed by `lines.` and the line key, when the preview's check refused.
     */
    private function page(Request $request, CatalogEmail $email, array $typed, array $loaded, ?string $version, ?array $edits = null, ?array $errors = null): View
    {
        $stored = EmailWording::edits();
        $lines = [];

        foreach ($email->lines as $line) {
            if (! EmailText::isLine($line['key'])) {
                continue;
            }

            $default = EmailText::defaultText($line['key']);
            $current = $stored[$line['key']] ?? $default;
            $value = is_string($typed[$line['key']] ?? null) ? $typed[$line['key']] : $current;

            $lines[] = $line + [
                'id' => 'line-'.str_replace('.', '-', $line['key']),
                'default' => $default,
                'edited' => array_key_exists($line['key'], $stored),
                'value' => $value,
                'loaded' => is_string($loaded[$line['key']] ?? null) ? $loaded[$line['key']] : $current,
                'long' => ! EmailText::isSubject($line['key']) && max(mb_strlen($default), mb_strlen($value)) > 60,
            ];
        }

        $previewing = $version !== null;
        $version ??= array_key_first($email->versions);
        $preview = $lines !== [] && $errors === null ? EmailPreview::render($email, $version, $edits ?? $stored) : null;

        $view = view('emails.edit', [
            'email' => $email,
            'lines' => array_values(array_filter($lines, fn (array $line) => ! $line['inside'])),
            'insideLines' => array_values(array_filter($lines, fn (array $line) => $line['inside'])),
            'readOnly' => $lines === [],
            'words' => $lines === [] ? [] : EmailPreview::words($email),
            'sampleWord' => array_key_exists('first_name', $email->examples) ? 'first_name' : array_key_first($email->examples),
            'version' => $version,
            'previewing' => $previewing,
            'preview' => $preview,
            'isAdmin' => $request->user()->can('manage-settings'),
        ]);

        return $errors === null ? $view : $view->with('errors', (new ViewErrorBag)->put('default', new MessageBag($errors)));
    }

    /**
     * The boxes and the hidden "as loaded" text, checked: both arrays of text, and only lines of this email, so nobody changes another email through this one's form. An empty box arrives as null (Laravel turns empty strings into null) and is put back to an empty string, so the save refuses it rather than treating it as Back to the default.
     *
     * @return array{0: array<string, string>, 1: array<string, string>}
     */
    private function input(Request $request, CatalogEmail $email): array
    {
        $request->validate([
            'lines' => ['nullable', 'array'],
            'lines.*' => ['nullable', 'string'],
            'loaded' => ['nullable', 'array'],
            'loaded.*' => ['nullable', 'string'],
        ], [
            'lines.array' => 'Please reload the page and try again.',
            'lines.*.string' => 'Type the wording as text.',
            'loaded.*' => 'Please reload the page and try again.',
        ]);

        $lines = array_map(fn ($text) => (string) $text, (array) $request->input('lines', []));
        $loaded = array_map(fn ($text) => (string) $text, (array) $request->input('loaded', []));
        $keys = $this->editableKeys($email);

        if (array_diff(array_map('strval', array_keys($lines + $loaded)), $keys) !== []) {
            throw ValidationException::withMessages(['lines' => 'This form had a line that isn\'t part of this email, so nothing was saved.']);
        }

        return [$lines, $loaded];
    }

    /** @return list<string> The keys of the email's lines that can be edited: every line in the wording file. */
    private function editableKeys(CatalogEmail $email): array
    {
        return array_values(array_filter($email->lineKeys(), fn (string $key) => EmailText::isLine($key)));
    }

    private function find(string $email): CatalogEmail
    {
        return EmailCatalog::find($email) ?? abort(404);
    }

    private function version(CatalogEmail $email, mixed $version): string
    {
        return is_string($version) && array_key_exists($version, $email->versions) ? $version : array_key_first($email->versions);
    }

    /** @return array<string, string> The action's refusals, keyed the way the form names each box. */
    private function lineErrors(ValidationException $e): array
    {
        $errors = [];

        foreach ($e->errors() as $key => $messages) {
            $errors['lines.'.$key] = $messages[0];
        }

        return $errors;
    }

    private static function firstSentence(string $text): string
    {
        return preg_match('/^.+?\.(?=\s|$)/', $text, $match) ? $match[0] : $text;
    }
}
