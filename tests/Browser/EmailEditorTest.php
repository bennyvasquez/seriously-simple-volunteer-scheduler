<?php

namespace Tests\Browser;

use App\Models\User;
use Database\Seeders\ExampleOrganizationSeeder;
use Laravel\Dusk\Browser;
use Tests\Browser\Concerns\ChecksAccessibility;
use Tests\DuskTestCase;

/**
 * The email editor (wireframes 61 to 63) in a real browser: a coordinator changes the reminder's opening, previews it, saves it, sees "Edited" on the list and puts it back. It also checks where the preview sits (beside the boxes on a computer, above them on a phone) and runs the accessibility checks at both widths.
 */
class EmailEditorTest extends DuskTestCase
{
    use ChecksAccessibility;

    private const OPENING = '#line-shift_reminder-intro-regular-date';

    public function test_a_coordinator_edits_previews_saves_and_puts_back_the_reminders_opening(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();

        $this->browse(function (Browser $browser) use ($coordinator) {
            $browser->resize(1280, 900)->loginAs($coordinator)->visit('/')
                ->clickLink('Emails we send')
                ->waitForText('Every email SSVS sends, grouped by who gets it.')
                ->assertMissing('.email-row .tag')
                ->clickLink('Shift reminder')
                ->waitForText('When it\'s sent');

            $browser->clear(self::OPENING)
                ->type(self::OPENING, 'Just a heads up: you\'re on {area} {date}, {time} ({timezone}).')
                ->press('Preview')
                ->waitForText('This preview uses your unsaved changes and example people.')
                ->assertSeeIn('.mail', 'Just a heads up: you\'re on Front desk Wednesday, October 21')
                ->assertInputValue(self::OPENING, 'Just a heads up: you\'re on {area} {date}, {time} ({timezone}).');

            $browser->press('Save')
                ->waitForText('Saved. Emails sent from now on use the new wording.')
                ->assertSee('Default: “You\'re on {area} {date}, {time} ({timezone}).”')
                ->assertSeeIn('.mail', 'Just a heads up');

            $browser->clickLink('Emails we send')->waitForText('Every email SSVS sends')->assertSeeIn('.email-row .tag', 'Edited');

            $browser->clickLink('Shift reminder')->waitForText('When it\'s sent')
                ->press('Back to the default')
                ->waitForText('That line is back to the default wording.')
                ->assertInputValue(self::OPENING, 'You\'re on {area} {date}, {time} ({timezone}).')
                ->assertDontSee('Back to the default');
        });
    }

    public function test_a_refused_line_shows_its_message(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();

        $this->browse(function (Browser $browser) use ($coordinator) {
            $browser->resize(390, 844)->loginAs($coordinator)->visit(route('emails.edit', 'shift_reminder'))
                ->clear('#line-shift_reminder-turn_off')
                ->type('#line-shift_reminder-turn_off', 'You can turn these reminders off on the {emails_page}.')
                // The menu is fixed to the bottom of a phone's screen, so the button is brought to the top before pressing it.
                ->scrollIntoView('button[value="PUT"]')
                ->press('Save')
                ->waitForText('Did you mean {emails_page_link}?')
                ->assertSee('Nothing was saved. Check the lines marked below.');

            $this->assertAccessible($browser);
        });
    }

    public function test_the_preview_sits_beside_the_boxes_on_a_computer_and_above_them_on_a_phone(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();

        $this->browse(function (Browser $browser) use ($coordinator) {
            $browser->resize(1280, 900)->loginAs($coordinator)->visit(route('emails.edit', 'shift_reminder'))->waitForText('When it\'s sent');
            [$preview, $lines] = $this->boxes($browser);
            $this->assertGreaterThanOrEqual($lines['right'], $preview['left'], 'On a computer the preview should be to the right of the boxes.');
            $this->assertLessThan($lines['bottom'], $preview['top']);
            $browser->screenshot('email-editor-1280');

            // On a phone it waits for Preview, then shows above the boxes.
            $browser->resize(390, 844)->visit(route('emails.edit', 'shift_reminder'))->waitForText('When it\'s sent')
                ->assertMissing('.email-preview');
            $browser->scrollIntoView('.email-actions button[formaction]')->press('Preview')->waitForText('This preview uses your unsaved changes');
            [$preview, $lines] = $this->boxes($browser);
            $this->assertLessThanOrEqual($lines['top'], $preview['bottom'], 'On a phone the preview should be above the boxes.');
            $browser->screenshot('email-editor-390-preview');
        });
    }

    public function test_the_editor_pages_are_accessible_on_a_computer_and_a_phone(): void
    {
        $coordinator = User::where('email', ExampleOrganizationSeeder::COORDINATOR)->firstOrFail();

        $this->browse(function (Browser $browser) use ($coordinator) {
            foreach ([1280 => 900, 390 => 844] as $width => $height) {
                $browser->resize($width, $height)->loginAs($coordinator)->visit(route('emails.index'))->waitForText('Every email SSVS sends');
                $this->assertAccessible($browser);

                $browser->visit(route('emails.edit', 'shift_reminder'))->waitForText('When it\'s sent');
                $this->assertAccessible($browser);

                // On a phone the preview, and its choice, show once Preview is pressed.
                $browser->scrollIntoView('.email-actions button[formaction]')->press('Preview')->waitForText('This preview uses your unsaved changes');
                $browser->scrollIntoView('input[value="A shadow with nobody to shadow"]')->radio('version', 'A shadow with nobody to shadow')->scrollIntoView('.email-version-button')->press('Show this version')->waitForText('Nobody is on this shift for you to shadow yet.', 5);
                $this->assertAccessible($browser);

                $browser->visit(route('emails.edit', 'cover_urgent'))->scrollIntoView('.email-actions button[formaction]')->press('Preview')->waitForText('This preview uses your unsaved changes');
                $this->assertAccessible($browser);

                $browser->visit(route('emails.edit', 'every_email'))->waitForText('These lines can\'t be changed here yet.');
                $this->assertAccessible($browser);
            }
        });
    }

    /** @return array{0: array<string, float>, 1: array<string, float>} The preview's and the boxes' positions on the page. */
    private function boxes(Browser $browser): array
    {
        return $browser->script([
            "const r = document.querySelector('.email-preview').getBoundingClientRect(); return {left: r.left, right: r.right, top: r.top + scrollY, bottom: r.bottom + scrollY};",
            "const r = document.querySelector('.email-lines').getBoundingClientRect(); return {left: r.left, right: r.right, top: r.top + scrollY, bottom: r.bottom + scrollY};",
        ]);
    }
}
