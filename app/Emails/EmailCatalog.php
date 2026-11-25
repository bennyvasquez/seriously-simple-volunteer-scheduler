<?php

namespace App\Emails;

use App\Models\Organization;
use App\Notifications\EmailText;

/**
 * Describes every email for the editor screens: its title and group, a sentence saying when it's sent, each line of wording with a short label and when it shows, example values for every variable, and the versions the preview can show. The wording itself stays in lang/en/emails.php (a test checks every line there is described here exactly once). The labels and "shows when" sentences are written for coordinators, from the comments above each line in that file.
 *
 * The "Every email" entry (`every_email`) covers the four lines Laravel's mail layout adds to every email. Their defaults are under `every_email.` in lang/en/emails.php like any other line, and App\Notifications\LaravelMailLines applies the edits when an email is sent.
 */
final class EmailCatalog
{
    private const OWN = 'To volunteers about their own shifts';

    private const COVER = 'Asking for cover';

    private const SHADOW = 'Shadowing';

    private const LEADS = 'To shift leads and coordinators';

    private const ACCOUNTS = 'Logging in and accounts';

    private const EVERY = 'Every email';

    private const SHIFT_LINK = 'Front desk, Thursday, October 22, 6:30 to 9:30pm';

    /** Example values shared by every email. An email's own examples win over these, and only the variables its lines use are kept. The code fills in any name said to someone else with User::shortName(), so those examples are short names (Sam E., Jordan P., Casey M., Chris C.); only the greeting's {first_name} is a first name. Chris C. is the example coordinator or admin on purpose. */
    private const EXAMPLES = [
        'organization' => 'Example Makers',
        'first_name' => 'Sam',
        'area' => 'Front desk',
        'date' => 'Thursday, October 22',
        'when' => 'Thursday, October 22, 6:30 to 9:30pm',
        'time' => '6:30 to 9:30pm',
        'timezone' => 'Eastern Time',
        'shift' => 'Front desk shift on Thursday, October 22, 6:30 to 9:30pm',
        'count' => '3',
        'code' => '123456',
        'new_address' => 'r•••@example.org',
        'invited_by' => 'Chris C.',
        'admin' => 'Chris C.',
        'coordinator' => 'Chris C.',
        'changed_by' => 'Chris C.',
        'shadow' => 'Casey M.',
        'taker' => 'Jordan P.',
        'releaser' => 'Sam E.',
        'names' => 'Sam E. and Jordan P.',
        'note' => 'Sorry, I have a family thing.',
        'leads' => 'Alex (Shift lead)',
        'weekday' => 'Thursday',
        'weekdays' => 'Thursdays',
        'slot' => 'Thursdays, 6:30 to 9:30pm',
        'slots' => 'Thursdays, Front desk',
        'how' => 'Thursday',
        'starting_on' => 'Thursday, October 22',
        'last_date' => 'Thursday, November 5',
        'from' => 'Thursday, October 29',
        'to' => 'December 10',
        'dates' => 'Thursday, October 22 and Thursday, October 29',
        'released_on' => 'Thursday, October 15',
        'required' => '2',
        'now' => '6 to 9pm',
        'was' => '5 to 8pm',
        'title' => 'Open house',
        'was_title' => 'Old title',
        'title_sentence' => ' It\'s now for Open house.',
        'reason' => 'a shift lead for Front desk',
        'reasons' => 'a shift lead for Front desk and a coordinator',
        'needs' => 'Needs 2 more people.',
        'released' => 'Sam released it on Thursday, October 15. We emailed 4 volunteers when it was released.',
        'shift_line' => 'Front desk, 6 to 9pm (was 5 to 8pm)',
        'date_bold' => 'Thursday, October 22',
        'shift_link' => self::SHIFT_LINK,
        'stop_link' => 'Stop getting cover requests',
        'emails_page_link' => 'Emails page',
        'reset_link' => 'Change your password',
    ];

    /** Emails whose title reads as a name in a sentence ("Changed the shift reminder's opening"). Every other email is named by its title in quotes ("the \"Shift changed\" email"), since many titles are sentences. */
    private const NOUNS = [
        'shift_reminder' => 'shift reminder',
        'cover_request' => 'cover request',
        'invitation' => 'invitation',
        'login_link' => 'login link email',
        'two_factor_code' => 'login code email',
    ];

    /** @var list<CatalogEmail>|null */
    private static ?array $all = null;

    /**
     * Every email, in the order the editor lists them within each group.
     *
     * @return list<CatalogEmail>
     */
    public static function all(): array
    {
        // The reminder sentence reads the Organization setting, so it's built each time; the rest never changes.
        self::$all ??= self::build();

        $reminder = self::reminderWhen();

        return array_map(fn (CatalogEmail $email) => $email->key === 'shift_reminder'
            ? new CatalogEmail($email->key, $email->title, $email->group, $reminder, $email->lines, $email->examples, $email->versions)
            : $email, self::$all);
    }

    public static function find(string $email): ?CatalogEmail
    {
        foreach (self::all() as $candidate) {
            if ($candidate->key === $email) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * How the change log names one line: "the shift reminder's opening", "the \"Put on a shift\" email's subject", "every email's sign-off". A line no email lists any more (a later release removed it) is "a line of" its email, or of "an email" when the email is gone too.
     */
    public static function lineName(string $key): string
    {
        $emailKey = explode('.', $key)[0];
        $email = self::find($emailKey);

        if ($email === null) {
            return 'a line of an email';
        }

        $whose = match (true) {
            $emailKey === 'every_email' => 'every email\'s',
            isset(self::NOUNS[$emailKey]) => 'the '.self::NOUNS[$emailKey].'\'s',
            default => 'the "'.$email->title.'" email\'s',
        };
        $line = collect($email->lines)->firstWhere('key', $key);

        if ($line === null) {
            return 'a line of '.substr($whose, 0, -2);
        }

        return $whose.' '.lcfirst($line['label']);
    }

    private static function reminderWhen(): string
    {
        // With no organization yet (a fresh install), show the default of 24 hours.
        $organization = Organization::query()->first();
        $hours = $organization === null ? 24 : $organization->reminder_hours;

        if ($hours === null) {
            return 'Reminders are off right now, so this email isn\'t sent. An admin can turn them on under Settings. When they\'re on, it goes before each shift to everyone on the shift who can log in and hasn\'t turned reminders off, once per person per shift.';
        }

        $unit = $hours === 1 ? 'hour' : 'hours';

        return "{$hours} {$unit} before each shift starts (an admin can change the timing under Settings). It goes to everyone on the shift who can log in and hasn't turned reminders off, once per person per shift. Someone put on the shift after that time isn't reminded, because they just signed up.";
    }

    /**
     * One email. `$lines` maps a line's key inside the email to [label, when it shows or null] and, for a line that's only used inside other lines (a phrase such as "Front desk shift on Thursday, October 22, 6:30 to 9:30pm"), a third element, true. A line can also have `'examples' => [variable => value]`, its own example values where the email's would read wrong in that line (the reminder's shadows aren't the people on the shift); a version's examples still win over them. `$versions` maps a name to the line keys it shows (inside the email, or a full key for a line that another email's lines add, such as the back on lines in a cancelled email), or to a list holding those keys and then the example overrides; with none given, a "Main" version shows every line. Lines used only inside other lines are left out of every version.
     *
     * @param  array<string, array{0: string, 1: ?string, 2?: bool, examples?: array<string, string>}>  $lines
     * @param  array<string, string>  $examples
     * @param  array<string, list<string>|array{0: list<string>, 1?: array<string, string>}>  $versions
     */
    private static function email(string $key, string $title, string $group, string $when, array $lines, array $examples = [], array $versions = []): CatalogEmail
    {
        $catalogLines = [];
        $inside = [];
        foreach ($lines as $suffix => $line) {
            $catalogLines[] = ['key' => "{$key}.{$suffix}", 'label' => $line[0], 'when' => $line[1], 'inside' => $line[2] ?? false, 'examples' => $line['examples'] ?? []];
            if ($line[2] ?? false) {
                $inside[] = $suffix;
            }
        }

        $versions = $versions ?: ['Main' => array_keys($lines)];
        $built = [];
        foreach ($versions as $name => $version) {
            [$suffixes, $overrides] = is_array($version[0] ?? null) ? [$version[0], $version[1] ?? []] : [$version, []];
            $suffixes = array_values(array_diff($suffixes, $inside));
            $built[$name] = ['lines' => array_map(fn (string $suffix) => str_contains($suffix, '.') && EmailText::isLine($suffix) ? $suffix : "{$key}.{$suffix}", $suffixes), 'examples' => $overrides];
        }

        // The variables of the email's own lines and of any other email's lines a version adds.
        $used = [];
        $keys = array_merge(array_column($catalogLines, 'key'), ...array_column($built, 'lines'));
        foreach (array_unique($keys) as $lineKey) {
            array_push($used, ...EmailText::allowedVariables($lineKey));
        }

        $merged = $examples + self::EXAMPLES;
        $kept = [];
        foreach (array_unique($used) as $variable) {
            $kept[$variable] = $merged[$variable] ?? '';
        }

        return new CatalogEmail($key, $title, $group, $when, $catalogLines, $kept, $built);
    }

    /** @return list<CatalogEmail> */
    private static function build(): array
    {
        $emails = [
            self::email('put_on_shift', 'Put on a shift', self::OWN, 'When a coordinator or shift lead puts someone on a shift. It goes to that person.', [
                'subject' => ['Subject', null],
                'body' => ['Message', null],
            ]),
            self::email('shift_reminder', 'Shift reminder', self::OWN, '', [
                'subject.today' => ['Subject', 'Shows when the shift is today'],
                'subject.tomorrow' => ['Subject', 'Shows when the shift is tomorrow'],
                'subject.date' => ['Subject', 'Shows when the shift is on a later day'],
                'greeting' => ['Greeting', null],
                'intro.regular.today' => ['Opening', 'Shows for someone on the shift when it\'s today'],
                'intro.regular.tomorrow' => ['Opening', 'Shows for someone on the shift when it\'s tomorrow'],
                'intro.regular.date' => ['Opening', 'Shows for someone on the shift when it\'s on a later day'],
                'intro.shadow.today' => ['Opening for a shadow', 'Shows for someone shadowing when the shift is today'],
                'intro.shadow.tomorrow' => ['Opening for a shadow', 'Shows for someone shadowing when the shift is tomorrow'],
                'intro.shadow.date' => ['Opening for a shadow', 'Shows for someone shadowing when the shift is on a later day'],
                'nobody_to_shadow' => ['Nobody to shadow', 'Shows for a shadow when only shadows are on the shift'],
                'nobody_else' => ['No other regulars on the shift', 'Shows when no other regular is on the shift, even if shadows are (they\'re listed separately)'],
                'also_on' => ['Who else is on the shift', 'Shows when other regulars are on the shift'],
                'shadowing' => ['Who is shadowing', 'Shows when shadows are on the shift', 'examples' => ['names' => 'Casey M.']],
                'time_changed' => ['Unanswered time change', 'Shows when the person hasn\'t answered a time change for this shift'],
                'still_works' => ['First button of the time change', 'Shows when the person hasn\'t answered a time change for this shift'],
                'cant' => ['Second button of the time change', 'Shows when the person hasn\'t answered a time change for this shift'],
                'login' => ['Line under the time change buttons', 'Shows when the person hasn\'t answered a time change for this shift'],
                'release' => ['Button to come off the shift', 'Shows unless the person has an unanswered time change, and it\'s left out for a shadow with nobody to shadow'],
                'turn_off' => ['Last line, about turning reminders off', null],
                'emails_page_label' => ['Words that link to the Emails page', null, true],
            ], ['names' => 'Jordan P.', 'time' => '6:30 to 9:30pm', 'date' => 'Wednesday, October 21', 'weekday' => 'Wednesday'], [
                'Someone on the shift' => [['subject.date', 'greeting', 'intro.regular.date', 'also_on', 'shadowing', 'release', 'turn_off', 'emails_page_label']],
                'Someone on the shift, the shift is tomorrow' => [['subject.tomorrow', 'greeting', 'intro.regular.tomorrow', 'also_on', 'release', 'turn_off', 'emails_page_label']],
                'Someone on the shift, the shift is today' => [['subject.today', 'greeting', 'intro.regular.today', 'nobody_else', 'shadowing', 'release', 'turn_off', 'emails_page_label']],
                'A shadow' => [['subject.date', 'greeting', 'intro.shadow.date', 'also_on', 'release', 'turn_off', 'emails_page_label']],
                'A shadow with nobody to shadow' => [['subject.date', 'greeting', 'intro.shadow.date', 'nobody_to_shadow', 'turn_off', 'emails_page_label']],
                'Someone with an unanswered time change' => [['subject.date', 'greeting', 'intro.regular.date', 'also_on', 'time_changed', 'still_works', 'cant', 'login', 'turn_off', 'emails_page_label']],
            ]),
            self::email('removed_from_shifts', 'Removed from shifts', self::OWN, 'When a coordinator takes someone off one or more shifts. It goes to that person, with one email for all the shifts taken at once.', [
                'subject.one' => ['Subject', 'Shows when it was one shift'],
                'subject.other' => ['Subject', 'Shows when it was several shifts'],
                'intro.one' => ['Opening', 'Shows when it was one shift'],
                'intro.other' => ['Opening', 'Shows when it was several shifts, above the list'],
                'shift_phrase' => ['How a shift is written', 'Used wherever the email names a shift', true],
                'shift_item' => ['Each shift in the list', 'Shows once for each shift when it was several shifts'],
                'outro' => ['Last line', null],
            ], [], [
                'One shift' => [['subject.one', 'intro.one', 'shift_phrase', 'outro']],
                'Several shifts' => [['subject.other', 'intro.other', 'shift_phrase', 'shift_item', 'outro']],
            ]),
            self::email('shift_changed', 'Shift changed', self::OWN, 'When a title is added to a shift someone is on, changed or taken away, including alongside a time change that was undone. It goes to everyone on the shift who can log in, once, listing what changed. A time change goes in the Shift time changed email instead.', [
                'subject.one' => ['Subject', 'Shows when one shift changed'],
                'subject.other' => ['Subject', 'Shows when several shifts changed'],
                'changed' => ['Start of each shift\'s line', null],
                'time' => ['New time', 'Not used right now: time changes go in the Shift time changed email.'],
                'title_now' => ['New title', 'Shows when the shift got a title or its title changed'],
                'title_removed' => ['Title taken away', 'Shows when the shift\'s title was taken away'],
                'outro' => ['Last line', null],
            ], [], [
                'It got a title' => [['subject.one', 'changed', 'title_now', 'outro']],
                'The title was taken away' => [['subject.one', 'changed', 'title_removed', 'outro'], ['was_title' => 'Open house']],
                'Several shifts' => [['subject.other', 'changed', 'title_now', 'outro']],
            ]),
            self::email('shift_time_changed', 'Shift time changed', self::OWN, 'When the time of a shift someone is on changes. It goes to everyone on the shift who can log in, with one email for all the shifts that edit moved, and asks whether the new time still works. After the buttons it can add lines about the person\'s other shifts in the same edit: a changed title, a cancelled shift or a shift that\'s back on.', [
                'greeting' => ['Greeting', null],
                'subject.moved' => ['Subject', 'Shows when every shift moved to the same time in one area'],
                'subject.back_on' => ['Subject', 'Shows when every shift is back on at the same new time in one area'],
                'subject.changed' => ['Subject', 'Shows when the shifts moved to different times or are in different areas'],
                'subject.back_on_several' => ['Subject', 'Shows when shifts are back on at different times or in different areas'],
                'intro.back_on_one' => ['Opening', 'Shows when one shift is back on at a new time'],
                'intro.back_on_several' => ['Opening', 'Shows when several shifts are back on at new times'],
                'intro.changed_one' => ['Opening', 'Shows when someone changed the time of one shift'],
                'intro.changed_several' => ['Opening', 'Shows when someone changed the time of several shifts'],
                'intro.by_system' => ['Opening', 'Shows when the system changed the time, so there\'s nobody to name'],
                'line' => ['Each shift\'s line', 'Shows once for each shift'],
                'line_with_title' => ['Each shift\'s line when its title changed too', 'Shows for a shift whose title changed as well'],
                'range' => ['One line for a run of weekly dates', 'Shows for several dates of one weekly slot, from the first to the last'],
                'shift_phrase' => ['How a shift is written', 'Used for cancelled and back on shifts that a closure change adds to this email', true],
                'question' => ['Question above the buttons', null],
                'still_works' => ['First button', null],
                'cant' => ['Second button', null],
                'login' => ['Line under the buttons', null],
                'lead_checks' => ['Added after the line under the buttons', 'Shows when the earliest shift starts in more than 48 hours'],
            ], ['time' => '6 to 9pm', 'from' => 'October 22'], [
                'One shift moved' => [['greeting', 'subject.moved', 'intro.changed_one', 'line', 'question', 'still_works', 'cant', 'login', 'lead_checks']],
                'A run of weekly dates' => [['greeting', 'subject.moved', 'intro.changed_several', 'range', 'question', 'still_works', 'cant', 'login', 'lead_checks']],
                'One shift moved by the system' => [['greeting', 'subject.moved', 'intro.by_system', 'line', 'question', 'still_works', 'cant', 'login', 'lead_checks']],
                'Time and title changed' => [['greeting', 'subject.moved', 'intro.changed_one', 'line_with_title', 'question', 'still_works', 'cant', 'login', 'lead_checks']],
                'A shift is back on' => [['greeting', 'subject.back_on', 'intro.back_on_one', 'line', 'question', 'still_works', 'cant', 'login', 'lead_checks']],
                'Several shifts are back on' => [['greeting', 'subject.back_on_several', 'intro.back_on_several', 'line', 'question', 'still_works', 'cant', 'login']],
                'Shifts changed in different ways' => [['greeting', 'subject.changed', 'intro.changed_several', 'line', 'question', 'still_works', 'cant', 'login', 'lead_checks']],
                'With more lines after the buttons' => [['greeting', 'subject.moved', 'intro.changed_one', 'line', 'question', 'still_works', 'cant', 'login', 'lead_checks', 'shift_changed.changed', 'shift_changed.title_now', 'shift_cancelled.body.one', 'shift_restored.body.one']],
            ]),
            self::email('shift_cancelled', 'Shift cancelled', self::OWN, 'When a shift someone is on is cancelled, by hand or by adding or changing a closure. It goes to everyone on the shift who can log in. When a closure change also brings other shifts back, the email says so too.', [
                'subject.one' => ['Subject', 'Shows when one shift was cancelled'],
                'subject.other' => ['Subject', 'Shows when several shifts were cancelled'],
                'subject_changes' => ['Subject when some shifts are also back on', 'Shows when the same email also says some shifts are back on'],
                'body.one' => ['Message', 'Shows when one shift was cancelled'],
                'body.other' => ['Opening', 'Shows when several shifts were cancelled, above the list'],
                'shift_phrase' => ['How a shift is written', 'Used wherever the email names a shift', true],
                'shift_item' => ['Each shift in the list', 'Shows once for each shift when several were cancelled'],
                'outro' => ['Last line', 'Shows when several shifts were cancelled'],
            ], ['reason' => 'Building closed.'], [
                'One shift' => [['subject.one', 'body.one', 'shift_phrase']],
                'Several shifts' => [['subject.other', 'body.other', 'shift_phrase', 'shift_item', 'outro']],
                'Some cancelled and some back on' => [['subject_changes', 'body.one', 'shift_restored.body.one']],
                'Several cancelled and several back on' => [['subject_changes', 'body.other', 'shift_item', 'outro', 'shift_restored.body.other', 'shift_restored.shift_item', 'shift_restored.outro']],
            ]),
            self::email('shift_restored', 'Shift back on', self::OWN, 'When a cancelled shift is brought back by hand, or a closure change brings it back without cancelling anything else. It goes to everyone on the shift who can log in.', [
                'subject.one' => ['Subject', 'Shows when one shift is back on'],
                'subject.other' => ['Subject', 'Shows when several shifts are back on'],
                'body.one' => ['Message', 'Shows when one shift is back on'],
                'body.other' => ['Opening', 'Shows when several shifts are back on, above the list'],
                'shift_phrase' => ['How a shift is written', 'Used wherever the email names a shift', true],
                'shift_item' => ['Each shift in the list', 'Shows once for each shift when several are back on'],
                'outro' => ['Last line', 'Shows when several shifts are back on'],
            ], [], [
                'One shift' => [['subject.one', 'body.one', 'shift_phrase']],
                'Several shifts' => [['subject.other', 'body.other', 'shift_phrase', 'shift_item', 'outro']],
            ]),
            self::email('made_regular', 'Made a regular', self::OWN, 'When a coordinator makes someone the regular on a weekly slot. It goes to that person.', [
                'subject' => ['Subject', null],
                'intro' => ['Opening', null],
                'how.every_week' => ['Words for a weekly slot', 'Fills in the opening when the slot is weekly', true],
                'how.every_other_week' => ['Words for an every other week slot', 'Fills in the opening when the slot is every other week', true],
                'shifts.one' => ['Shifts already on the schedule', 'Shows when one shift is already on the schedule for them'],
                'shifts.other' => ['Shifts already on the schedule', 'Shows when several shifts are already on the schedule for them'],
            ], ['how' => 'every week'], [
                'Weekly, no shifts yet' => [['subject', 'intro', 'how.every_week']],
                'Weekly, one shift on the schedule' => [['subject', 'intro', 'how.every_week', 'shifts.one']],
                'Every other week, several shifts' => [['subject', 'intro', 'how.every_other_week', 'shifts.other'], ['how' => 'every other week']],
            ]),
            self::email('regular_ended', 'Regular ended', self::OWN, 'When a coordinator ends someone\'s time as the regular on a weekly slot. It goes to that person.', [
                'subject' => ['Subject', null],
                'ended' => ['Opening when it has ended', 'Shows when the last date has already passed'],
                'ending' => ['Opening when it\'s ending', 'Shows when the last date is still ahead'],
                'shifts_intro' => ['Above the list of shifts', 'Shows when they were taken off upcoming shifts'],
                'shift_phrase' => ['How a shift is written', 'Used wherever the email names a shift', true],
                'shift_item' => ['Each shift in the list', 'Shows once for each shift they were taken off'],
                'outro' => ['Last line', null],
            ], [], [
                'Ending on a date, with shifts' => [['subject', 'ending', 'shifts_intro', 'shift_phrase', 'shift_item', 'outro']],
                'Already ended' => [['subject', 'ended', 'outro']],
            ]),
            self::email('shifts_released', 'You\'re off a shift', self::COVER, 'When a volunteer says they can\'t make a shift, or gives up their weekly slot. It goes to that volunteer, with one email for everything they released at once. Leaving only a shadow spot sends no email, and a volunteer a coordinator moves back to shadowing gets the Moved back to shadowing email instead.', [
                'greeting' => ['Greeting', null],
                'subject_no_longer_regular' => ['Subject when they gave up their weekly slot', 'Shows when they only gave up their weekly slot and none of its dates are on the calendar yet'],
                'no_longer_regular' => ['No longer the regular', 'Shows when they gave up their weekly slot'],
                'none_on_calendar' => ['None of the dates are on the calendar', 'Shows after that line when none of the slot\'s dates are on the calendar yet'],
                'subject.one' => ['Subject', 'Shows when they released one shift'],
                'subject.other' => ['Subject', 'Shows when they released several shifts'],
                'off_shift' => ['Opening', 'Shows when they released one shift'],
                'nobody_asked' => ['Nobody else was asked', 'Shows when it was one shift and nobody else was asked to cover it'],
                'asked_one' => ['Others were asked to cover', 'Shows when it was one shift and other volunteers were asked to cover it'],
                'shadows_told.one' => ['One shadow was told', 'Shows when it was one shift and one shadow was going to shadow it'],
                'shadows_told.other' => ['Several shadows were told', 'Shows when it was one shift and several shadows were going to shadow it'],
                'stay_regular' => ['They stay the regular', 'Shows when it was one shift and they stay the regular for it'],
                'take_back.one' => ['They can take it back', 'Shows when it was one shift that other volunteers were asked to cover'],
                'take_back.other' => ['They can take them back', 'Shows when it was several shifts and other volunteers were asked to cover some'],
                'action' => ['Button', null],
                'list_intro' => ['Above the list of shifts', 'Shows when it was several shifts'],
                'shift_item' => ['Each shift in the list', 'Shows once for each shift when it was several shifts'],
                'shadow_spot_item' => ['A shadow spot in the list', 'Shows for a shift where they only left a shadow spot'],
                'nobody_asked_on_date' => ['Nobody else was asked on a date', 'Shows once for each shift in the list that still has the people it needs'],
                'asked_all' => ['Others were asked to cover all of them', 'Shows when others were asked to cover every shift in the list'],
                'asked_rest' => ['Others were asked to cover the rest', 'Shows instead of that when some shifts in the list had nobody else asked'],
                'shadows_told_on_dates.one' => ['One shadow was told, on some dates', 'Shows when it was several shifts and one shadow was going to shadow some of them'],
                'shadows_told_on_dates.other' => ['Several shadows were told, on some dates', 'Shows when several shadows were going to shadow some of the shifts'],
                'shadows_told_every_date.one' => ['One shadow was told, on every date', 'Shows when one shadow was going to shadow every shift in the list'],
                'shadows_told_every_date.other' => ['Several shadows were told, on every date', 'Shows when several shadows were going to shadow every shift in the list'],
                'stay_regular_weekday' => ['They stay the regular on one weekday', 'Shows when it was several shifts and they stay the regular for one weekday'],
                'stay_regular_weekly' => ['They stay the regular on different weekdays', 'Shows when it was several shifts and they stay the regular on weekly slots on different days'],
            ], ['names' => 'Casey M.', 'dates' => 'Thursday, October 22 and Thursday, October 29'], [
                'One shift, others asked to cover' => [['greeting', 'subject.one', 'off_shift', 'asked_one', 'take_back.one', 'action']],
                'One shift, a shadow told and they stay the regular' => [['greeting', 'subject.one', 'off_shift', 'asked_one', 'shadows_told.one', 'stay_regular', 'take_back.one', 'action']],
                'One shift, nobody else asked' => [['greeting', 'subject.one', 'off_shift', 'nobody_asked', 'action']],
                'Several shifts' => [['greeting', 'subject.other', 'list_intro', 'shift_item', 'shadow_spot_item', 'nobody_asked_on_date', 'asked_rest', 'shadows_told_on_dates.one', 'stay_regular_weekday', 'take_back.other', 'action']],
                'Several shifts, others asked to cover all' => [['greeting', 'subject.other', 'list_intro', 'shift_item', 'asked_all', 'shadows_told_every_date.other', 'stay_regular_weekly', 'take_back.other', 'action'], ['names' => 'Casey M. and Jo K.']],
                'Gave up the weekly slot' => [['greeting', 'subject.other', 'list_intro', 'shift_item', 'asked_all', 'shadows_told_every_date.other', 'no_longer_regular', 'take_back.other', 'action'], ['names' => 'Casey M. and Jo K.']],
                'Gave up the weekly slot, no dates on the calendar' => [['greeting', 'subject_no_longer_regular', 'no_longer_regular', 'none_on_calendar', 'action']],
            ]),
            self::email('cover_request', 'Cover request', self::COVER, 'When a volunteer says they can\'t make a shift, and again 72 hours before it if nobody has taken it. It goes to cleared volunteers who could take the shift and haven\'t turned cover request emails off.', [
                'greeting' => ['Greeting', null],
                'subject_still_open' => ['Subject of the "Still open" email', 'Shows in the reminder sent 72 hours before the shift when nobody has taken it'],
                'still_open' => ['Opening of the "Still open" email', 'Shows in the reminder sent 72 hours before the shift'],
                'subject_gave_up.one' => ['Subject when a weekly slot was given up', 'Shows when someone gave up their weekly slot and one date needs covering'],
                'subject_gave_up.other' => ['Subject when a weekly slot was given up', 'Shows when someone gave up their weekly slot and several dates need covering'],
                'gave_up' => ['Opening when a weekly slot was given up', 'Shows when someone gave up their weekly slot, above the dates'],
                'subject.one' => ['Subject', 'Shows when one shift needs covering'],
                'subject.other' => ['Subject', 'Shows when several shifts need covering'],
                'cant_make.one' => ['Opening', 'Shows when it was one shift'],
                'cant_make.other' => ['Opening', 'Shows when it was several shifts'],
                'shift_item' => ['The shift', 'Shows when it was one shift (not in the Still open email)'],
                'shift_link' => ['Each shift in the list', 'Shows once for each shift when it was several shifts (not in the Still open email)'],
                'note' => ['Their note', 'Shows when the person who released the shift left a note'],
                'action' => ['Button', 'Shows when it was one shift'],
                'first_person_one' => ['Line under the button', 'Shows when it was one shift'],
                'first_person_several' => ['Line after the list', 'Shows when it was several shifts'],
                'reason' => ['Why they got this email', null],
                'stop' => ['Last line, about turning these emails off', null],
                'stop_label' => ['Words that link to the Emails page', null, true],
            ], ['when' => 'Thursday, October 22, 6:30 to 9:30pm', 'weekdays' => 'Thursdays'], [
                'One shift' => [['greeting', 'subject.one', 'cant_make.one', 'shift_item', 'action', 'first_person_one', 'reason', 'stop', 'stop_label']],
                'One shift with a note' => [['greeting', 'subject.one', 'cant_make.one', 'shift_item', 'note', 'action', 'first_person_one', 'reason', 'stop', 'stop_label']],
                'Several shifts' => [['greeting', 'subject.other', 'cant_make.other', 'shift_link', 'note', 'first_person_several', 'reason', 'stop', 'stop_label']],
                'Still open' => [['greeting', 'subject_still_open', 'still_open', 'action', 'first_person_one', 'reason', 'stop', 'stop_label']],
                'Still open, with a note' => [['greeting', 'subject_still_open', 'still_open', 'note', 'action', 'first_person_one', 'reason', 'stop', 'stop_label']],
                'A weekly slot was given up' => [['greeting', 'subject_gave_up.other', 'gave_up', 'shift_link', 'first_person_several', 'reason', 'stop', 'stop_label']],
            ]),
            self::email('shift_taken', 'You took a shift', self::COVER, 'When a volunteer takes an open shift, covers a shift someone released, or takes back one they released. It goes to that volunteer.', [
                'subject' => ['Subject', null],
                'greeting_thanks' => ['Greeting', 'Shows unless they took their own shift back'],
                'greeting_back_on' => ['Greeting when they took their own shift back', 'Shows when they took back a shift they released themselves'],
                'regular' => ['Now the regular', 'Shows when they became the regular'],
                'when_area' => ['Time and place', null],
                'note' => ['Their note', 'Shows when the person who released the shift left a note'],
                'shadowing.one' => ['One shadow', 'Shows when one shadow is on the shift'],
                'shadowing.other' => ['Several shadows', 'Shows when several shadows are on the shift'],
                'not_on_other_shift' => ['Dates left out for another shift', 'Shows when they became the regular and some dates were left out because they\'re on another shift then'],
                'not_on_full' => ['Dates left out because they\'re full', 'Shows when some dates were left out because those shifts already have the people they need'],
            ], ['names' => 'Casey M.', 'dates' => 'Thursday, October 29 or Thursday, November 5', 'how' => 'every other Thursday', 'date' => 'Thursday, October 22'], [
                'Covering a shift' => [['subject', 'greeting_thanks', 'when_area', 'note']],
                'Covering a shift, with a shadow' => [['subject', 'greeting_thanks', 'when_area', 'note', 'shadowing.one']],
                'Taking their own shift back' => [['subject', 'greeting_back_on', 'when_area']],
                'Becoming the regular' => [['subject', 'greeting_thanks', 'regular', 'when_area', 'not_on_other_shift', 'not_on_full']],
                'Taking an open shift' => [['subject', 'greeting_thanks', 'when_area']],
            ]),
            self::email('shift_covered', 'Someone is covering your shift', self::COVER, 'When someone takes a shift a volunteer released. It goes to the volunteer who released it, with one email when the person covers several of their dates at once.', [
                'subject.one' => ['Subject', 'Shows when someone is covering one shift'],
                'subject.other' => ['Subject', 'Shows when someone is covering several shifts'],
                'greeting' => ['Greeting', null],
                'body.one' => ['Message', 'Shows when it was one shift'],
                'body.other' => ['Message above the list of dates', 'Shows when it was several shifts'],
            ], ['taker' => 'Jordan P.'], [
                'One shift' => [['subject.one', 'greeting', 'body.one']],
                'Several shifts' => [['subject.other', 'greeting', 'body.other']],
            ]),
            self::email('shadow_signed_up', 'You\'re shadowing a shift', self::SHADOW, 'When a volunteer signs up to shadow a shift. It goes to that volunteer.', [
                'subject' => ['Subject', null],
                'greeting' => ['Greeting', null],
                'intro' => ['Opening', null],
                'regulars' => ['Who you\'ll shadow', 'Shows when the shift has regulars to shadow'],
                'outro' => ['Last line', null],
            ], [], [
                'With regulars to shadow' => [['subject', 'greeting', 'intro', 'regulars', 'outro']],
                'Nobody else on the shift' => [['subject', 'greeting', 'intro', 'outro']],
            ]),
            self::email('shadow_spot', 'Your shadow spot changed', self::SHADOW, 'When the people a shadow would shadow change: when a regular can\'t make the shift (on hold), when someone covers it (back on), and the day before when nobody has (still uncovered). It goes to the shadow, with one email when several of their shifts change at once.', [
                'on_hold.subject.one' => ['Subject when it\'s on hold', 'Shows when one of their shadow shifts is on hold'],
                'on_hold.subject.other' => ['Subject when they\'re on hold', 'Shows when several of their shadow shifts are on hold'],
                'on_hold.intro.one' => ['Opening when it\'s on hold', 'Shows when one shadow shift is on hold'],
                'on_hold.intro.other' => ['Opening when they\'re on hold', 'Shows when several are on hold, above the list'],
                'on_hold.outro.one' => ['Last line when it\'s on hold', 'Shows when one shadow shift is on hold'],
                'on_hold.outro.other' => ['Last line when they\'re on hold', 'Shows when several are on hold'],
                'back_on.subject.one' => ['Subject when it\'s back on', 'Shows when one of their shadow shifts is back on'],
                'back_on.subject.other' => ['Subject when they\'re back on', 'Shows when several of their shadow shifts are back on'],
                'back_on.intro.one' => ['Message when it\'s back on', 'Shows when one shadow shift is back on'],
                'back_on.intro.other' => ['Opening when they\'re back on', 'Shows when several are back on, above the list'],
                'still_uncovered.subject.one' => ['Subject when nobody has taken it', 'Shows when one shadow shift still has nobody to shadow'],
                'still_uncovered.subject.other' => ['Subject when nobody has taken them', 'Shows when several shadow shifts still have nobody to shadow'],
                'still_uncovered.intro.one' => ['Opening when nobody has taken it', 'Shows when one shadow shift still has nobody to shadow'],
                'still_uncovered.intro.other' => ['Opening when nobody has taken them', 'Shows when several still have nobody, above the list'],
                'still_uncovered.reach_out' => ['Last line when nobody has taken it', 'Shows in both of those emails'],
                'still_uncovered.knows.one' => ['One lead knows', 'Added after the last line when the area has one lead'],
                'still_uncovered.knows.other' => ['Several leads know', 'Added after the last line when the area has several leads'],
            ], ['count' => '2'], [
                'On hold, one shift' => [['on_hold.subject.one', 'on_hold.intro.one', 'on_hold.outro.one']],
                'On hold, several shifts' => [['on_hold.subject.other', 'on_hold.intro.other', 'on_hold.outro.other']],
                'Back on, one shift' => [['back_on.subject.one', 'back_on.intro.one']],
                'Back on, several shifts' => [['back_on.subject.other', 'back_on.intro.other']],
                'Still nobody to shadow, one shift' => [['still_uncovered.subject.one', 'still_uncovered.intro.one', 'still_uncovered.reach_out']],
                'Still nobody to shadow, one shift, with a lead' => [['still_uncovered.subject.one', 'still_uncovered.intro.one', 'still_uncovered.reach_out', 'still_uncovered.knows.one']],
                'Still nobody to shadow, several shifts, with leads' => [['still_uncovered.subject.other', 'still_uncovered.intro.other', 'still_uncovered.reach_out', 'still_uncovered.knows.other'], ['leads' => 'Alex (Shift lead) and Jo (Shift lead)']],
            ]),
            self::email('cleared', 'Cleared for regular shifts', self::SHADOW, 'When a coordinator clears a volunteer to take regular shifts. It goes to that volunteer.', [
                'subject' => ['Subject', null],
                'greeting' => ['Greeting', null],
                'body' => ['Message', null],
            ]),
            self::email('moved_back', 'Moved back to shadowing', self::SHADOW, 'When a coordinator moves a volunteer back to shadowing. It goes to that volunteer.', [
                'subject' => ['Subject', null],
                'greeting' => ['Greeting', null],
                'intro.one' => ['Opening', 'Shows when one shadow shift is needed before they\'re reviewed'],
                'intro.other' => ['Opening', 'Shows when several shadow shifts are needed before they\'re reviewed'],
                'asked_intro' => ['Above the shifts others were asked to cover', 'Shows when they were taken off shifts that others were asked to cover'],
                'nobody_asked_intro' => ['Above the shifts that were already full', 'Shows when they were taken off shifts that still have the people they need'],
                'no_longer_regular' => ['No longer the regular', 'Shows when they were the regular on weekly slots'],
            ], [], [
                'Nothing released' => [['subject', 'greeting', 'intro.other']],
                'One shift' => [['subject', 'greeting', 'intro.one', 'asked_intro']],
                'Shifts and a weekly slot' => [['subject', 'greeting', 'intro.other', 'asked_intro', 'nobody_asked_intro', 'no_longer_regular']],
            ]),
            self::email('shadow_signed_up_lead', 'Someone is shadowing your area', self::LEADS, 'When a volunteer signs up to shadow a shift. It goes to the area\'s shift leads.', [
                'subject' => ['Subject', null],
                'intro' => ['Opening', null],
                'regulars.one' => ['Who is on the shift', 'Shows when one regular is on the shift'],
                'regulars.other' => ['Who is on the shift', 'Shows when several regulars are on the shift'],
                'action' => ['Button', null],
            ], [], [
                'Several regulars on the shift' => [['subject', 'intro', 'regulars.other', 'action']],
                'One regular on the shift' => [['subject', 'intro', 'regulars.one', 'action'], ['names' => 'Sam E.']],
                'Nobody else on the shift' => [['subject', 'intro', 'action']],
            ]),
            self::email('shadow_left', 'A shadow can\'t make it', self::LEADS, 'When a shadow takes themselves off a shadow shift. It goes to everyone with the coordinator role who can log in, with one email when they leave several shifts at once.', [
                'subject.one' => ['Subject', 'Shows when it was one shift'],
                'subject.other' => ['Subject', 'Shows when it was several shifts'],
                'intro.one' => ['Opening', 'Shows when it was one shift'],
                'intro.other' => ['Opening', 'Shows when it was several shifts, above the list'],
                'shift_link' => ['Each shift in the list', 'Shows once for each shift when it was several shifts'],
                'note' => ['Their note', 'Shows when the shadow left a note'],
                'action' => ['Button', 'Shows when it was one shift'],
                'outro' => ['Last line', null],
            ], [], [
                'One shift' => [['subject.one', 'intro.one', 'action', 'outro']],
                'One shift with a note' => [['subject.one', 'intro.one', 'note', 'action', 'outro']],
                'Several shifts with a note' => [['subject.other', 'intro.other', 'shift_link', 'note', 'outro']],
            ]),
            self::email('cover_urgent', 'Urgent: a shift needs someone', self::LEADS, 'When a released shift starts in less than 24 hours and nobody has taken it. It goes to the area\'s shift leads and everyone with the coordinator role who can log in, with one email when several shifts need someone.', [
                'subject.one' => ['Subject', 'Shows when one shift needs someone'],
                'subject.other' => ['Subject', 'Shows when several shifts need someone'],
                'unclaimed.one' => ['Opening', 'Shows when it was one shift'],
                'unclaimed.other' => ['Opening', 'Shows when it was several shifts'],
                'shift_line' => ['The shift', 'Shows when it was one shift'],
                'shift_link_line' => ['Each shift in the list', 'Shows once for each shift when it was several shifts'],
                'needs.one' => ['How many more people, one', 'Fills in the shift lines when it needs one more person'],
                'needs.other' => ['How many more people, several', 'Fills in the shift lines when it needs none or several more people'],
                'released' => ['Who released it', 'Fills in the shift lines'],
                'emailed.one' => ['Who was emailed, one', 'Fills in the shift lines when one volunteer was emailed'],
                'emailed.other' => ['Who was emailed, several', 'Fills in the shift lines when none or several were emailed'],
                'emailed_again.one' => ['Who was emailed again, one', 'Fills in the shift lines when one volunteer was emailed and the email went out again 72 hours before'],
                'emailed_again.other' => ['Who was emailed again, several', 'Fills in the shift lines when none or several were emailed and it went out again 72 hours before'],
                'action' => ['Button', 'Shows when it was one shift'],
                'marked_urgent.one' => ['Marked urgent', 'Shows when it was one shift'],
                'marked_urgent.other' => ['Marked urgent', 'Shows when it was several shifts'],
                'reason' => ['Why they got this email', null],
            ], ['count' => '2', 'needs' => 'Needs 2 more people.'], [
                'One shift' => [['subject.one', 'unclaimed.one', 'shift_line', 'needs.other', 'released', 'emailed.other', 'action', 'marked_urgent.one', 'reason']],
                'One shift, one more person needed' => [['subject.one', 'unclaimed.one', 'shift_line', 'needs.one', 'released', 'emailed.one', 'action', 'marked_urgent.one', 'reason'], ['count' => '1', 'needs' => 'Needs 1 more person.']],
                'One shift, emailed again 72 hours before' => [['subject.one', 'unclaimed.one', 'shift_line', 'needs.other', 'released', 'emailed_again.other', 'action', 'marked_urgent.one', 'reason']],
                'Several shifts' => [['subject.other', 'unclaimed.other', 'shift_link_line', 'needs.one', 'needs.other', 'released', 'emailed.one', 'emailed.other', 'marked_urgent.other', 'reason']],
                'Several shifts, emailed again 72 hours before' => [['subject.other', 'unclaimed.other', 'shift_link_line', 'needs.one', 'needs.other', 'released', 'emailed_again.one', 'emailed_again.other', 'marked_urgent.other', 'reason']],
            ]),
            self::email('shift_taken_lead', 'Someone took a shift in your area', self::LEADS, 'When a volunteer takes a place themselves in an area: covering a released shift, taking back their own, or taking an open place. It isn\'t sent when a coordinator or lead puts someone on a shift. It goes to the area\'s shift leads who can log in.', [
                'subject_covered' => ['Subject when someone covered a shift', 'Shows when someone filled a cover request'],
                'took_back' => ['Opening when they took their own shift back', 'Shows when the person who released the shift took it back'],
                'covering' => ['Opening when someone is covering', 'Shows when someone covered a released shift'],
                'regular' => ['Now the regular', 'Shows when they became the regular. After an open place that wasn\'t a cover request, it takes the place of the opening line.'],
                'subject_took' => ['Subject when someone took an open place', 'Shows when someone took an open place that wasn\'t a cover request'],
                'took_open' => ['Opening when someone took an open place', 'Shows when someone took an open place that wasn\'t a cover request and didn\'t become the regular'],
                'action' => ['Button', null],
            ], [], [
                'Someone is covering' => [['subject_covered', 'covering', 'action']],
                'They took their own shift back' => [['subject_covered', 'took_back', 'action']],
                'Someone became the regular' => [['subject_covered', 'covering', 'regular', 'action']],
                'Someone took an open place' => [['subject_took', 'took_open', 'action']],
                'Someone took an open place and became the regular' => [['subject_took', 'regular', 'action']],
            ]),
            self::email('no_answer_lead', 'No answer yet to a time change', self::LEADS, '48 hours before a shift whose time changed, if someone on it still hasn\'t said whether the new time works. It goes once, to the area\'s shift leads who can log in, or to the coordinators when the area has no lead.', [
                'greeting' => ['Greeting', null],
                'subject' => ['Subject', null],
                'intro' => ['Opening', null],
                'shift' => ['The shift and who hasn\'t answered', null],
                'shift_line' => ['The area and the times', 'Fills in the shift line', true],
                'outro' => ['Line after the shift', null],
                'action' => ['Button', null],
                'only_reminder' => ['Line after the button', null],
                'reason' => ['Why they got this email', null],
            ], ['reason' => 'a shift lead for Front desk', 'now' => '5 to 8pm', 'was' => '6:30 to 9:30pm', 'shift_line' => 'Front desk, 5 to 8pm (was 6:30 to 9:30pm)']),
            self::email('login_link', 'Login link', self::ACCOUNTS, 'When someone asks for a login link on the log in screen. It goes to that person\'s email address.', [
                'subject' => ['Subject', null],
                'intro' => ['Opening', null],
                'action' => ['Button', null],
                'outro' => ['Last line', null],
            ]),
            self::email('two_factor_code', 'Login code', self::ACCOUNTS, 'When someone logging in asks for their second step code by email instead of using their authenticator app. It goes to that person.', [
                'subject' => ['Subject', null],
                'code' => ['Message with the code', null],
                'outro' => ['Last line', null],
            ]),
            self::email('confirm_its_you', 'Confirm it\'s you', self::ACCOUNTS, 'When someone who has no password asks to change their login email address. It goes to their current address.', [
                'subject' => ['Subject', null],
                'intro' => ['Opening', null],
                'action' => ['Button', null],
                'outro' => ['Last line', null],
            ]),
            self::email('email_change_confirmation', 'Confirm a new email address', self::ACCOUNTS, 'When someone asks to change their login email address. It goes to the new address, and nothing changes until they open the link.', [
                'subject' => ['Subject', null],
                'intro' => ['Message', null],
                'action' => ['Button', null],
            ]),
            self::email('email_changed', 'Login email changed', self::ACCOUNTS, 'After someone\'s login email address changes. It goes to their old address, so the real owner hears about a change they didn\'t make.', [
                'subject' => ['Subject', null],
                'body' => ['Message', null],
            ]),
            self::email('invitation', 'Invitation', self::ACCOUNTS, 'When a coordinator or admin invites someone to the volunteer schedule. It goes to the person invited.', [
                'subject' => ['Subject', null],
                'greeting' => ['Greeting', null],
                'intro_by_person' => ['Opening when we know who invited them', 'Shows when we know who sent the invitation'],
                'intro' => ['Opening when we don\'t', 'Shows when we don\'t know who sent the invitation'],
                'action' => ['Button', null],
                'outro' => ['Last line', null],
            ], [], [
                'Invited by a person' => [['subject', 'greeting', 'intro_by_person', 'action', 'outro']],
                'Sender not known' => [['subject', 'greeting', 'intro', 'action', 'outro']],
            ]),
            self::email('two_factor_reset', 'Second step reset', self::ACCOUNTS, 'When an admin resets someone\'s second step. It goes to that person.', [
                'subject' => ['Subject', null],
                'body' => ['Message', null],
            ]),
            self::email('two_factor_attempts', 'Too many wrong codes', self::ACCOUNTS, 'When a lot of wrong second step codes have been typed on someone\'s account, in a day or an hour. It goes to that person, at most once a day.', [
                'subject' => ['Subject', null],
                'greeting' => ['Greeting', null],
                'paused' => ['Opening', null],
                'advice' => ['What to do', null],
                'reset' => ['Last line, with the link to reset a password', null],
                'reset_label' => ['Words that link to reset a password', null, true],
            ]),
            self::email('password_changed', 'Password changed', self::ACCOUNTS, 'After someone\'s password changes. It goes to that person.', [
                'subject' => ['Subject', null],
                'intro' => ['Opening', null],
                'outro' => ['Last line', null],
            ]),
            self::email('every_email', 'Every email', self::EVERY, 'Added by Laravel to emails that need it: the heading when an email has no greeting, the sign-off, the line under each button, and the footer. These four lines read the same in every email.', [
                'heading' => ['Heading when an email has no greeting', 'Shows at the top of an email that has no greeting of its own. The default is "Hello!".'],
                'sign_off' => ['Sign-off', 'Shows at the end of every email. The default is "Regards,".'],
                'button_trouble' => ['Line under each button', 'Shows under the button in every email that has one. The button\'s address always comes right after it.'],
                'footer' => ['Footer', 'Shows at the bottom of every email, after the year and the organization\'s name. The default is "All rights reserved.". It can\'t use [ ] * _ ` ~ | \\ < or >.'],
            ], ['button' => 'Log in'], [
                'Main' => [['heading', 'sign_off', 'button_trouble', 'footer']],
            ]),
        ];

        return $emails;
    }
}
