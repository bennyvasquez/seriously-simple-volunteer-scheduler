<?php

namespace Tests\Feature;

use App\Actions\Accounts\GrantRole;
use App\Actions\Emails\SaveEmailWording;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\PutOnShift;
use App\Emails\EmailCatalog;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\ShadowSpotNotice;
use App\Models\Area;
use App\Models\EmailWording;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\ClearedNotification;
use App\Notifications\ConfirmItsYouNotification;
use App\Notifications\CoverRequestNotification;
use App\Notifications\CoverUrgentNotification;
use App\Notifications\EmailChangeConfirmationNotification;
use App\Notifications\EmailChangedNotification;
use App\Notifications\EmailText;
use App\Notifications\InvitationNotification;
use App\Notifications\LaravelMailLines;
use App\Notifications\LoginLinkNotification;
use App\Notifications\MadeRegularNotification;
use App\Notifications\MovedBackNotification;
use App\Notifications\NoAnswerLeadNotification;
use App\Notifications\PasswordChangedNotification;
use App\Notifications\PlainText;
use App\Notifications\PutOnShiftNotification;
use App\Notifications\RegularEndedNotification;
use App\Notifications\RemovedFromShiftsNotification;
use App\Notifications\ShadowLeftNotification;
use App\Notifications\ShadowSignedUpLeadNotification;
use App\Notifications\ShadowSignedUpNotification;
use App\Notifications\ShadowSpotNotification;
use App\Notifications\ShiftCancelledNotification;
use App\Notifications\ShiftChangedNotification;
use App\Notifications\ShiftCoveredNotification;
use App\Notifications\ShiftReminderNotification;
use App\Notifications\ShiftRestoredNotification;
use App\Notifications\ShiftsReleasedNotification;
use App\Notifications\ShiftTakenLeadNotification;
use App\Notifications\ShiftTakenNotification;
use App\Notifications\ShiftTimeChangedNotification;
use App\Notifications\TwoFactorCodeNotification;
use App\Notifications\TwoFactorResetNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Markdown;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Lang;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use Illuminate\Translation\Translator;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class EmailTextTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    protected function setUp(): void
    {
        parent::setUp();

        app('translator')->addLines([
            'emails.test_email.hello' => 'Hi {first_name}, see you at {area}.',
            'emails.test_email.plain' => 'No variables here.',
            'emails.test_email.braces' => 'Keep { this }, {That}, {} and {a-b} as they are.',
            'emails.test_email.count.one' => '{names} is shadowing.',
            'emails.test_email.count.other' => '{names} are shadowing.',
            'emails.test_email.with_link' => 'Take {shift_link} at {area}.',
            'emails.test_email.subject' => 'Your {area} shift',
            'emails.test_email.note' => '{first_name} left a note: {note}',
        ], 'en');
    }

    public function test_a_line_fills_in_its_variables(): void
    {
        $this->assertSame('Hi Jordan, see you at Front desk.', EmailText::line('test_email.hello', ['first_name' => 'Jordan', 'area' => 'Front desk']));
    }

    public function test_a_missing_key_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailText::line('test_email.nope');
    }

    public function test_an_unfilled_variable_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        EmailText::line('test_email.hello', ['first_name' => 'Jordan']);
    }

    public function test_an_unused_variable_is_ignored(): void
    {
        $this->assertSame('No variables here.', EmailText::line('test_email.plain', ['area' => 'Front desk']));
    }

    public function test_choice_picks_one_or_other(): void
    {
        $this->assertSame('Alex R. is shadowing.', EmailText::choice('test_email.count', 1, ['names' => 'Alex R.']));
        $this->assertSame('Alex R. and Sam E. are shadowing.', EmailText::choice('test_email.count', 2, ['names' => 'Alex R. and Sam E.']));
    }

    public function test_braces_that_arent_a_variable_name_are_left_alone(): void
    {
        $this->assertSame('Keep { this }, {That}, {} and {a-b} as they are.', EmailText::line('test_email.braces'));
    }

    public function test_a_value_that_looks_like_a_variable_is_not_filled_in_again(): void
    {
        $this->assertSame('Hi {area}, see you at Front desk.', EmailText::line('test_email.hello', ['first_name' => '{area}', 'area' => 'Front desk']));
    }

    /**
     * One example of each email class (more where a class reads very differently, such as one shift or several), keyed by a label for failure messages. Built from a small example schedule: a Front desk area with one-time shifts and a weekly Thursday slot.
     *
     * @return array<string, array{0: object, 1: User}>
     */
    protected function emailExamples(): array
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8, 'name' => 'Example Makers']);
        $area = Area::factory()->create(['name' => 'Front desk']);
        $coordinator = User::factory()->cleared()->create(['name' => 'Chris Coordinator', 'email' => 'chris@example.org']);
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator, null);
        $sam = User::factory()->cleared()->create(['name' => 'Sam Example', 'email' => 'sam@example.org']);
        $jordan = User::factory()->cleared()->create(['name' => 'Jordan Pat', 'email' => 'jordan@example.org']);
        $casey = User::factory()->cleared()->create(['name' => 'Casey Shadow', 'email' => 'casey@example.org']);

        $shiftOn = function (string $date, string $from = '18:30', string $to = '21:30', ?string $title = null, int $needed = 2) use ($coordinator, $area): Shift {
            return app(CreateOneTimeShift::class)->handle($coordinator, $area, $date, $from, $to, $needed, 1, $title)->load('area');
        };
        $first = $shiftOn('2026-10-22');
        $second = $shiftOn('2026-10-29');
        $titled = $shiftOn('2026-11-05', '18:30', '21:30', 'Open house');
        $both = collect([$first, $second]);
        $roster = $shiftOn('2026-10-21', '09:00', '12:00', 'Open house');
        app(PutOnShift::class)->handle($coordinator, $roster, $sam, AssignmentType::Regular);
        app(PutOnShift::class)->handle($coordinator, $roster, $jordan, AssignmentType::Regular);
        app(PutOnShift::class)->handle($coordinator, $roster, $casey, AssignmentType::Shadow);
        $emptyShadowShift = $shiftOn('2026-10-20', '09:00', '12:00');
        $emptyShadowAssignment = app(PutOnShift::class)->handle($coordinator, $emptyShadowShift, $casey, AssignmentType::Shadow);
        $rosterAssignment = $roster->assignments()->where('user_id', $casey->id)->firstOrFail();

        // Thursdays, 6:30 to 9:30pm, generated ahead.
        $slot = WeeklySlot::factory()->create(['area_id' => $area->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 2, 'valid_from' => '2026-10-01']);
        app(GenerateShifts::class)->handle(Organization::current());
        $thursday = fn (string $date) => Shift::query()->where('weekly_slot_id', $slot->id)->whereDate('local_date', $date)->firstOrFail()->load('area');
        $regular = app(AddRegular::class)->handle(null, $slot, $sam, '2026-10-22', '2026-11-05');
        $slotRun = collect(['2026-10-22', '2026-10-29', '2026-11-05'])->map($thursday);

        $change = fn (Shift $shift, string $was, ?string $wasTitle = null) => ['shift' => $shift, 'was' => $was, 'wasTitle' => $wasTitle];
        $moved = fn (Shift $shift) => ['shift' => $shift, 'was_starts_at' => $shift->starts_at->subHour(), 'was_ends_at' => $shift->ends_at];
        $releasedAt = CarbonImmutable::parse('2026-10-18 10:00', self::TIMEZONE);
        $url = 'https://example.org/login/example-token';

        $examples = [
            'login link' => [new LoginLinkNotification($url), $sam],
            'two factor code' => [new TwoFactorCodeNotification('123456'), $sam],
            'confirm its you' => [new ConfirmItsYouNotification($url), $sam],
            'email change confirmation' => [new EmailChangeConfirmationNotification($url), $sam],
            'email changed' => [new EmailChangedNotification('new.address@example.org'), $sam],
            'invitation' => [new InvitationNotification($url), $sam],
            'invitation by a coordinator' => [new InvitationNotification($url, 'Chris C.'), $sam],
            'two factor reset' => [new TwoFactorResetNotification($coordinator), $sam],
            'password changed' => [new PasswordChangedNotification, $sam],
            'put on shift' => [new PutOnShiftNotification($first), $sam],
            'cleared' => [new ClearedNotification($coordinator, Organization::current()), $sam],
            'removed from one shift' => [new RemovedFromShiftsNotification(collect([$first])), $sam],
            'removed from several shifts' => [new RemovedFromShiftsNotification($both), $sam],
            'shadow signed up, nobody else' => [new ShadowSignedUpNotification($first, []), $casey],
            'shadow signed up, one regular' => [new ShadowSignedUpNotification($first, ['Sam E.']), $casey],
            'shadow signed up, two regulars' => [new ShadowSignedUpNotification($first, ['Sam E.', 'Jordan P.']), $casey],
            'shadow signed up, to a lead' => [new ShadowSignedUpLeadNotification($first, 'Casey S.', ['Sam E.', 'Jordan P.']), $coordinator],
            'shadow signed up, to a lead, nobody else' => [new ShadowSignedUpLeadNotification($first, 'Casey S.', []), $coordinator],
            'made regular, no shifts' => [new MadeRegularNotification($slot->load('area'), $regular, 0), $sam],
            'made regular, one shift' => [new MadeRegularNotification($slot->load('area'), $regular, 1), $sam],
            'made regular, several shifts' => [new MadeRegularNotification($slot->load('area'), $regular, 3), $sam],
            'regular ending' => [new RegularEndedNotification($slot->load('area'), '2026-11-05', $slotRun), $sam],
            'regular ended' => [new RegularEndedNotification($slot->load('area'), '2026-10-01', collect()), $sam],
            'shadow left, no note' => [new ShadowLeftNotification('Casey S.', collect([$first]), null), $coordinator],
            'shadow left, with note' => [new ShadowLeftNotification('Casey S.', $both, 'Sick this week.'), $coordinator],
            'moved back, nothing released' => [new MovedBackNotification($coordinator, Organization::current(), 2, collect(), []), $sam],
            'moved back, one shift' => [new MovedBackNotification($coordinator, Organization::current(), 1, collect([$first]), []), $sam],
            'moved back, shifts and a slot' => [new MovedBackNotification($coordinator, Organization::current(), 3, $both, ['Thursdays, Front desk'], [$second->id]), $sam],
            'shift changed, time' => [new ShiftChangedNotification(collect([$change($first, '5 to 8pm')])), $sam],
            'shift changed, title' => [new ShiftChangedNotification(collect([$change($titled, '6:30 to 9:30pm', null)])), $sam],
            'shift changed, several' => [new ShiftChangedNotification(collect([$change($first, '5 to 8pm'), $change($titled, '6:30 to 9:30pm', 'Old title')])), $sam],
            'shift cancelled, one' => [new ShiftCancelledNotification(collect([$first]), 'Building closed'), $sam],
            'shift cancelled, several' => [new ShiftCancelledNotification($both, 'Building closed.'), $sam],
            'shift cancelled, with restored' => [new ShiftCancelledNotification(collect([$first]), 'Building closed', collect([$second])), $sam],
            'shift restored, one' => [new ShiftRestoredNotification($first), $sam],
            'shift restored, several' => [new ShiftRestoredNotification($both), $sam],
            'shifts released, one' => [new ShiftsReleasedNotification(collect([$first])), $sam],
            'shifts released, one with shadows and regular' => [new ShiftsReleasedNotification(collect([$first]), [$first->id => ['Casey S.']], [$first->id], [$first->id]), $sam],
            'shifts released, one nobody asked' => [new ShiftsReleasedNotification(collect([$first]), [], [], [], null, [$first->id]), $sam],
            'shifts released, several' => [new ShiftsReleasedNotification($both, [$first->id => ['Casey S.', 'Alex R.']], [$second->id], [], null, [$first->id]), $sam],
            'shifts released, gave up slot' => [new ShiftsReleasedNotification($both, [], [], [], ['weekday' => 'Thursday', 'from' => 'Thursday, October 29']), $sam],
            'shifts released, gave up slot, none on calendar' => [new ShiftsReleasedNotification(collect(), [], [], [], ['weekday' => 'Thursday', 'from' => 'Thursday, October 29']), $sam],
            'cover request, one' => [new CoverRequestNotification(collect([$first]), 'Sam', null), $jordan],
            'cover request, several with note' => [new CoverRequestNotification($both, 'Sam', 'Sorry, thanks <b>so much</b>!'), $jordan],
            'cover request, still open' => [new CoverRequestNotification(collect([$first]), 'Sam', null, true), $jordan],
            'cover request, gave up slot' => [new CoverRequestNotification($both, 'Sam', null, false, true), $jordan],
            'cover urgent, one' => [new CoverUrgentNotification($first, 'Sam', $releasedAt, 4, false, 'a shift lead for Front desk'), $coordinator],
            'cover urgent, resent' => [new CoverUrgentNotification($first, 'Sam', $releasedAt, 1, true, 'a coordinator'), $coordinator],
            'cover urgent, several' => [(new CoverUrgentNotification($first, 'Sam', $releasedAt, 4, false, 'a shift lead for Front desk'))->merge(new CoverUrgentNotification($second, 'Jordan', $releasedAt, 0, true, 'a coordinator')), $coordinator],
            'shadow spot on hold, one' => [new ShadowSpotNotification($first, ShadowSpotNotice::OnHold, 'Sam'), $casey],
            'shadow spot on hold, several' => [(new ShadowSpotNotification($first, ShadowSpotNotice::OnHold, 'Sam'))->merge(new ShadowSpotNotification($second, ShadowSpotNotice::OnHold, 'Sam')), $casey],
            'shadow spot back on, one' => [new ShadowSpotNotification($first, ShadowSpotNotice::BackOn, 'Sam', 'Jordan P.'), $casey],
            'shadow spot back on, several' => [(new ShadowSpotNotification($first, ShadowSpotNotice::BackOn, 'Sam', 'Jordan P.'))->merge(new ShadowSpotNotification($second, ShadowSpotNotice::BackOn, 'Sam', 'Jordan P.')), $casey],
            'shadow spot still uncovered, one' => [new ShadowSpotNotification($first, ShadowSpotNotice::StillUncovered, 'Sam'), $casey],
            'shadow spot still uncovered, one with a lead' => [new ShadowSpotNotification($first, ShadowSpotNotice::StillUncovered, 'Sam', null, 'Alex (Shift lead)'), $casey],
            'shadow spot still uncovered, several with leads' => [(new ShadowSpotNotification($first, ShadowSpotNotice::StillUncovered, 'Sam', null, 'Alex (Shift lead)'))->merge(new ShadowSpotNotification($second, ShadowSpotNotice::StillUncovered, 'Sam', null, 'Jo (Shift lead)')), $casey],
            'shift taken, cover' => [new ShiftTakenNotification($first, 'Sam'), $jordan],
            'shift taken, cover with note and shadows' => [new ShiftTakenNotification($first, 'Sam', 'Thanks <b>so much</b>', ['Casey S.', 'Alex R.']), $jordan],
            'shift taken, one shadow' => [new ShiftTakenNotification($first, 'Sam', null, ['Casey S.']), $jordan],
            'shift taken, took back' => [new ShiftTakenNotification($first, null, null, [], true), $sam],
            'shift taken, regular' => [new ShiftTakenNotification($first, 'Sam', null, [], false, 'Thursday', ['Thursday, October 29'], ['Thursday, November 5']), $jordan],
            'shift taken, open shift' => [new ShiftTakenNotification($first), $jordan],
            'shift taken, to a lead, covering' => [new ShiftTakenLeadNotification($first, 'Jordan P.', 'Sam'), $coordinator],
            'shift taken, to a lead, took back' => [new ShiftTakenLeadNotification($first, 'Sam E.', 'Sam', true), $coordinator],
            'shift taken, to a lead, regular' => [new ShiftTakenLeadNotification($first, 'Jordan P.', 'Sam', false, 'Thursday'), $coordinator],
            'shift taken, to a lead, open place' => [new ShiftTakenLeadNotification($first, 'Jordan P.'), $coordinator],
            'shift covered, one' => [new ShiftCoveredNotification($first, 'Jordan'), $sam],
            'shift covered, several' => [(new ShiftCoveredNotification($first, 'Jordan'))->merge(new ShiftCoveredNotification($second, 'Jordan')), $sam],
        ];

        $tail = [
            // Several shifts of one slot, in a row: reads as a range.
            'time changed, a run of one slot' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', $slotRun->map($moved), 'Chris C.'), $sam],
            'time changed, one shift' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first)]), 'Chris C.'), $sam],
            'time changed, one shift, by the system' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first)]), null), $sam],
            'time changed, two separate shifts' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first), $moved($roster)]), 'Chris C.'), $sam],
            'time changed, with a title change' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($titled) + ['was_title' => 'Old title']]), 'Chris C.'), $sam],
            'time changed, back on, one' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first) + ['back_on' => true]]), 'Chris C.'), $sam],
            'time changed, back on, several' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', $both->map(fn (Shift $shift) => $moved($shift) + ['back_on' => true]), 'Chris C.'), $sam],
            'time changed, starting within 48 hours, with more lines' => [(new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($emptyShadowShift)]), 'Chris C.'))->withTitleChanges(collect([$change($titled, '6:30 to 9:30pm', 'Old title')]))->withMoreLines(['Your Front desk shift on Thursday, October 29 is cancelled.']), $sam],
            'no answer yet' => [new NoAnswerLeadNotification($first, ['Sam E.', 'Jordan P.'], '5 to 8pm', 'a shift lead for Front desk'), $coordinator],
            'no answer yet, one person' => [new NoAnswerLeadNotification($first, ['Sam E.'], '5 to 8pm', 'a coordinator'), $coordinator],
            'reminder, regular with others' => [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), ['Jordan P.'], ['Casey S.'], null), $sam],
            'reminder, regular alone' => [new ShiftReminderNotification($first, app(PutOnShift::class)->handle($coordinator, $first, $jordan, AssignmentType::Regular), [], [], null), $jordan],
            'reminder, shadow with regulars' => [new ShiftReminderNotification($roster, $rosterAssignment, ['Sam E.', 'Jordan P.'], [], null), $casey],
            'reminder, shadow with nobody to shadow' => [new ShiftReminderNotification($emptyShadowShift, $emptyShadowAssignment, [], [], null), $casey],
            'reminder, with an open time change' => [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), ['Jordan P.'], [], '01HZZZZZZZZZZZZZZZZZZZZZZZ'), $sam],
        ];

        // Today and tomorrow read differently from a date, so the reminder is built on each.
        $examples = $examples + $tail;
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', self::TIMEZONE));
        $examples['reminder, tomorrow'] = [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), ['Jordan P.'], [], null), $sam];
        $this->travelTo(CarbonImmutable::parse('2026-10-21 07:00', self::TIMEZONE));
        $examples['reminder, today'] = [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), ['Jordan P.'], [], null), $sam];

        return $examples;
    }

    /**
     * Sends the notification to the person through the real mail channel and returns what the test mailer caught.
     *
     * @return array{subject: string, html: string, text: string}
     */
    protected function sentMail(object $notification, User $user): array
    {
        $transport = app('mail.manager')->mailer()->getSymfonyTransport();
        $before = count($transport->messages());
        $user->notifyNow($notification);
        $messages = $transport->messages();
        $this->assertCount($before + 1, $messages, 'The notification sent no email.');
        $email = $messages->last()->getOriginalMessage();

        return ['subject' => (string) $email->getSubject(), 'html' => (string) $email->getHtmlBody(), 'text' => (string) $email->getTextBody()];
    }

    public function test_every_email_renders_without_leftover_braces(): void
    {
        // setUp adds made up lines for the other tests, which would hide the real file.
        app('translator')->setLoaded([]);
        $examples = $this->emailExamples();

        // Every email class has an example: a new one fails here until it's added to the list above.
        $classes = collect(File::files(app_path('Notifications')))
            ->map(fn ($file) => 'App\\Notifications\\'.$file->getBasename('.php'))
            ->reject(fn (string $class) => in_array($class, [PlainText::class, EmailText::class, LaravelMailLines::class], true))
            ->sort()->values()->all();
        $covered = collect($examples)->map(fn (array $example) => $example[0]::class)->unique()->sort()->values()->all();
        $this->assertSame($classes, $covered, 'Every email class needs an example in emailExamples().');

        foreach ($examples as $label => [$notification, $user]) {
            $mail = $this->sentMail($notification, $user);

            foreach ($mail as $part => $text) {
                $this->assertNotSame('', $text, "{$label}: the {$part} is empty.");
                $this->assertDoesNotMatchRegularExpression('/\{[a-z][a-z0-9_]*\}/', $text, "{$label}: the {$part} has a variable left unfilled.");
                $this->assertDoesNotMatchRegularExpression('/\bemails\.[a-z][a-z0-9_]*\./', $text, "{$label}: the {$part} shows a wording key instead of wording.");
            }
        }
    }

    /**
     * The editor's catalog (App\Emails\EmailCatalog) has to list every line an email really renders, so a new or renamed line can't be missing from the editor. Each example's mail is built (toMail), and every wording line read while it's built has to be one of the catalog lines of that email (its own, or another email's lines its versions add, such as the back on lines in a cancelled email). Lines read in a notification's constructor, such as most subjects, aren't recorded here: the whole wording file is checked against the catalog in EmailCatalogTest.
     */
    public function test_every_line_an_email_renders_is_in_the_catalog(): void
    {
        app('translator')->setLoaded([]);
        $examples = $this->emailExamples();

        $recorder = new class(app('translator')->getLoader(), 'en') extends Translator
        {
            /** @var list<string> */
            public array $read = [];

            public function get($key, array $replace = [], $locale = null, $fallback = true)
            {
                $line = parent::get($key, $replace, $locale, $fallback);

                if (is_string($key) && str_starts_with($key, 'emails.') && is_string($line) && $line !== $key) {
                    $this->read[] = substr($key, strlen('emails.'));
                }

                return $line;
            }
        };
        $recorder->setFallback('en');
        $this->app->instance('translator', $recorder);
        Lang::clearResolvedInstance('translator');

        foreach ($examples as $label => [$notification, $user]) {
            $recorder->read = [];
            $notification->toMail($user);
            $read = array_unique($recorder->read);
            $this->assertNotEmpty($read, "{$label}: no wording lines were recorded, so this check isn't looking at anything.");

            $email = EmailCatalog::find(Str::snake(Str::beforeLast(class_basename($notification), 'Notification')));
            $this->assertNotNull($email, "{$label}: its email isn't in the catalog.");
            $listed = [...$email->lineKeys(), ...array_merge(...array_column($email->versions, 'lines'))];

            foreach ($read as $key) {
                $this->assertContains($key, $listed, "{$label}: it renders [{$key}], which the catalog doesn't list for this email.");
            }
        }
    }

    public function test_emails_are_signed_and_sent_in_the_organizations_name(): void
    {
        // setUp's made-up lines stop the real wording file from loading, so start from the real file.
        app('translator')->setLoaded([]);
        config(['app.name' => 'SSVS', 'mail.from.name' => 'SSVS']);
        Organization::factory()->create(['name' => 'Example Makers']);
        $sam = User::factory()->create();

        $sam->notifyNow(new PasswordChangedNotification);

        $email = app('mail.manager')->mailer()->getSymfonyTransport()->messages()->last()->getOriginalMessage();
        $html = (string) $email->getHtmlBody();
        $this->assertSame('Example Makers', $email->getFrom()[0]->getName());
        $this->assertStringContainsString('Example Makers. All rights reserved.', $html);
        $this->assertStringContainsString("Regards,<br>\nExample Makers", $html);
        $this->assertStringNotContainsString('SSVS', $html);
        $this->assertStringNotContainsString('SSVS', (string) $email->getTextBody());
    }

    /** Stores edited lines straight in the table, the way SaveEmailWording leaves them, and forgets the request's copy. @param array<string, string> $lines */
    private function edit(array $lines): void
    {
        DB::table('email_wordings')->insert(collect($lines)->map(fn (string $text, string $key) => ['key' => $key, 'text' => $text, 'updated_by' => null, 'created_at' => now(), 'updated_at' => now()])->values()->all());
        EmailWording::forgetCache();
    }

    public function test_a_default_line_is_still_a_plain_string(): void
    {
        $this->assertIsString(EmailText::line('test_email.hello', ['first_name' => '<b>Jordan</b>', 'area' => '**Front desk**']));
        $this->assertSame('Hi <b>Jordan</b>, see you at **Front desk**.', EmailText::line('test_email.hello', ['first_name' => '<b>Jordan</b>', 'area' => '**Front desk**']));
    }

    public function test_an_edited_line_escapes_its_own_text_and_fills_in_values_as_before(): void
    {
        $this->edit(['test_email.hello' => "  <b>Hey</b>   *there* {first_name},\n[see](https://example.org) you at {area} & #1! "]);

        $line = EmailText::line('test_email.hello', ['first_name' => '<i>Jordan</i>', 'area' => '**Front desk**']);

        // The typed text becomes entities, Markdown punctuation included, with its spaces and line breaks made single spaces. A value is escaped the way Blade escapes it, so Markdown in a value reads as it does in a default line.
        $this->assertInstanceOf(HtmlString::class, $line);
        $this->assertSame('&lt;b&gt;Hey&lt;/b&gt; &#42;there&#42; &lt;i&gt;Jordan&lt;/i&gt;, &#91;see&#93;(https://example.org) you at **Front desk** &amp; &#35;1&#33;', $line->toHtml());
    }

    public function test_an_edited_line_that_starts_like_a_list_or_a_rule_stays_text(): void
    {
        foreach (['- one {area}' => '&#45; one', '+ one {area}' => '&#43; one', '1. one {area}' => '1&#46; one', '2) one {area}' => '2&#41; one', '--- {area}' => '&#45;--', '=== {area}' => '&#61;==', '> {area}' => '&gt; '] as $edit => $start) {
            EmailWording::query()->delete();
            $this->edit(['test_email.hello' => $edit]);

            $this->assertStringStartsWith($start, EmailText::line('test_email.hello', ['first_name' => 'Jordan', 'area' => 'Front desk'])->toHtml(), $edit);
        }
    }

    public function test_an_edit_that_starts_like_markdown_stays_one_paragraph(): void
    {
        $vars = ['first_name' => 'Jordan', 'area' => 'Front desk', 'note' => 'See you.'];
        $paragraph = fn (HtmlString $line) => preg_match('#^<p>[^<]*</p>$#', trim((string) Markdown::parse($line->toHtml()))) === 1;

        foreach (['- item', '1. item', '+ item', '---', '# heading', '2) item', '=== item', '> quote'] as $start) {
            EmailWording::query()->delete();
            $this->edit(['test_email.hello' => $start.' {first_name} {area}', 'test_email.note' => $start.' {first_name}: {note}']);

            $this->assertTrue($paragraph(EmailText::line('test_email.hello', $vars)), "{$start} on an ordinary line");
            $this->assertTrue($paragraph(EmailText::plainTextLine('test_email.note', $vars)), "{$start} on a note line");

            // An edit that starts with a value that's empty, or only spaces, is covered too.
            EmailWording::query()->delete();
            $this->edit(['test_email.hello' => '{first_name}'.$start.' {area}', 'test_email.note' => '{first_name}'.$start.' {note}']);
            foreach (['', '   '] as $empty) {
                $this->assertTrue($paragraph(EmailText::line('test_email.hello', ['first_name' => $empty] + $vars)), "{$start} after an empty value");
                $this->assertTrue($paragraph(EmailText::plainTextLine('test_email.note', ['first_name' => $empty] + $vars)), "{$start} after an empty value on a note line");
            }
        }
    }

    public function test_a_link_variable_goes_in_as_the_code_built_it(): void
    {
        $this->edit(['test_email.with_link' => 'Press {shift_link} <b>now</b>, {area}.']);

        $line = EmailText::line('test_email.with_link', ['shift_link' => EmailText::link('Front desk, Thursday', 'https://example.org/shifts/1?a=1'), 'area' => '[Front desk](https://example.org)']);

        $this->assertSame('Press [Front desk, Thursday](https://example.org/shifts/1?a=1) &lt;b&gt;now&lt;/b&gt;, [Front desk](https://example.org).', $line->toHtml());
    }

    public function test_a_link_variable_that_is_a_plain_string_is_a_code_bug_in_an_edited_line(): void
    {
        $this->edit(['test_email.with_link' => 'Press {shift_link} now, {area}.']);

        $this->expectException(LogicException::class);

        EmailText::line('test_email.with_link', ['shift_link' => '<script>alert(1)</script>', 'area' => 'Front desk']);
    }

    public function test_a_link_must_point_to_http_or_https(): void
    {
        $this->assertSame('[A](http://example.org/1)', EmailText::link('A', 'http://example.org/1')->toHtml());

        foreach (['javascript:alert(1)', 'data:text/html,hi', '//example.org/1', '/shifts/1', 'mailto:a@example.org'] as $url) {
            try {
                EmailText::link('A', $url);
                $this->fail("A link to [{$url}] should have been refused.");
            } catch (LogicException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_a_link_the_code_builds_escapes_the_text_a_person_typed(): void
    {
        $link = EmailText::link('A]b*c<d&e, Thursday', 'https://example.org/shifts/1');

        $this->assertSame('[A&#93;b&#42;c&lt;d&amp;e, Thursday](https://example.org/shifts/1)', $link->toHtml());
        $this->assertSame('[Emails page](https://example.org/emails)', EmailText::link(new HtmlString('Emails page'), 'https://example.org/emails')->toHtml());
        // The default line and an edit both put it in as it is, so it isn't escaped twice.
        $this->assertSame('Take [A&#93;](https://example.org/1) at Front desk.', EmailText::line('test_email.with_link', ['shift_link' => EmailText::link('A]', 'https://example.org/1'), 'area' => 'Front desk'])->toHtml());
        $this->edit(['test_email.with_link' => '{area}: {shift_link}']);
        $this->assertSame('Front desk: [A&#93;](https://example.org/1)', EmailText::line('test_email.with_link', ['shift_link' => EmailText::link('A]', 'https://example.org/1'), 'area' => 'Front desk'])->toHtml());
    }

    public function test_an_edited_subject_is_plain_text_on_one_line(): void
    {
        $this->edit(['test_email.subject' => "Your <b>{area}</b>\n& *shift*"]);

        $subject = EmailText::line('test_email.subject', ['area' => 'Front & back']);

        $this->assertSame('Your <b>Front & back</b> & *shift*', $subject);
    }

    public function test_a_subject_given_an_edited_line_as_a_value_has_no_entities(): void
    {
        $this->edit(['test_email.hello' => 'Hi & {first_name}']);
        $hello = EmailText::line('test_email.hello', ['first_name' => 'Jo', 'area' => 'x']);

        $this->assertSame('Your Hi & Jo shift', EmailText::line('test_email.subject', ['area' => $hello]));
    }

    public function test_an_edited_line_given_as_a_value_is_not_escaped_twice(): void
    {
        $this->edit(['test_email.plain' => 'Edited <b>&</b> *line*']);
        $inner = EmailText::line('test_email.plain');

        // In a default line, the default's own text is escaped the way Blade would have escaped the whole line.
        $outer = EmailText::line('test_email.hello', ['first_name' => 'Jo & "Sam"', 'area' => $inner]);
        $this->assertSame('Hi Jo &amp; &quot;Sam&quot;, see you at Edited &lt;b&gt;&amp;&lt;/b&gt; &#42;line&#42;.', $outer->toHtml());

        // And in an edited line.
        $this->edit(['test_email.count.one' => '{names}!']);
        $this->assertSame('Edited &lt;b&gt;&amp;&lt;/b&gt; &#42;line&#42;&#33;', EmailText::choice('test_email.count', 1, ['names' => $inner])->toHtml());
    }

    public function test_a_line_with_typed_text_escapes_its_values_too_edited_or_not(): void
    {
        $vars = ['first_name' => '**Jo**', 'area' => '[x](https://example.org) <b>'];
        $this->assertSame('Hi &#42;&#42;Jo&#42;&#42;, see you at &#91;x&#93;(https://example.org) &lt;b&gt;.', EmailText::plainTextLine('test_email.hello', $vars)->toHtml());

        $this->edit(['test_email.hello' => '*Note* from {first_name}: {area}']);
        $this->assertSame('&#42;Note&#42; from &#42;&#42;Jo&#42;&#42;: &#91;x&#93;(https://example.org) &lt;b&gt;', EmailText::plainTextLine('test_email.hello', $vars)->toHtml());
    }

    public function test_join_escapes_plain_parts_only_when_a_part_is_html(): void
    {
        $this->assertSame('One & two. <b>Three</b>', EmailText::join('One & two.', ' ', '<b>Three</b>'));
        $this->assertSame('', EmailText::join());

        $joined = EmailText::join('One & two.', ' ', new HtmlString('&#42;three&#42;'));
        $this->assertInstanceOf(HtmlString::class, $joined);
        $this->assertSame('One &amp; two. &#42;three&#42;', $joined->toHtml());
    }

    public function test_allowed_variables_are_the_default_lines(): void
    {
        $this->assertSame(['first_name', 'area'], EmailText::allowedVariables('test_email.hello'));
        $this->assertSame([], EmailText::allowedVariables('test_email.plain'));
        $this->assertSame([], EmailText::allowedVariables('test_email.braces'));
        $this->assertSame(['names'], EmailText::allowedVariables('test_email.count.other'));
    }

    public function test_edited_text_reaches_the_email_as_plain_text(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);
        $sam = User::factory()->create(['name' => 'Sam Example', 'email' => 'sam@example.org']);
        $typed = '<script>alert(1)</script> [Click here](https://example.org) **bold** <a href="https://example.org">x</a> https://example.org/bare & _under_ `code` ![img](https://example.org/i.png)';
        $this->edit([
            'login_link.intro' => $typed,
            'login_link.subject' => '<b>Log in</b> & **now**',
            'login_link.action' => '**Log** <i>in</i>',
        ]);

        $mail = $this->sentMail(new LoginLinkNotification('https://example.org/login/example-token'), $sam);

        $this->assertSame('<b>Log in</b> & **now**', $mail['subject']);

        foreach (['<script', '<a href="https://example.org"', '<a href="https://example.org/bare', '<strong>', '<em>', '<code>', '<img', '<i>'] as $markup) {
            $this->assertStringNotContainsString($markup, $mail['html'], $markup);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; [Click here](https://example.org) **bold** &lt;a href=', $mail['html']);
        $this->assertStringContainsString('**Log** &lt;i&gt;in&lt;/i&gt;', $mail['html']);
        // The button still goes where the code sends it.
        $this->assertStringContainsString('href="https://example.org/login/example-token"', $mail['html']);

        $this->assertStringContainsString($typed, $mail['text']);
        $this->assertStringContainsString('**Log** <i>in</i>', $mail['text']);
        $this->assertStringNotContainsString('&lt;', $mail['text']);
        $this->assertStringNotContainsString('&#', $mail['text']);
    }

    public function test_every_line_edited_renders_escaped_once_in_every_email(): void
    {
        app('translator')->setLoaded([]);
        $typed = 'Edited <b>*x*</b> & [y](https://example.org)';
        $keys = array_keys(array_filter(Arr::dot(Lang::get('emails', [], 'en')), 'is_string'));
        $this->edit(collect($keys)->mapWithKeys(fn (string $key) => [$key => $typed.' '.implode(' ', array_map(fn (string $name) => '{'.$name.'}', EmailText::allowedVariables($key)))])->all());
        $examples = $this->emailExamples();

        foreach ($examples as $label => [$notification, $user]) {
            $mail = $this->sentMail($notification, $user);

            $this->assertStringStartsWith($typed, $mail['subject'], "{$label}: subject");
            $this->assertStringContainsString('Edited &lt;b&gt;*x*&lt;/b&gt; &amp; [y](https://example.org)', $mail['html'], "{$label}: html");
            $this->assertStringContainsString($typed, $mail['text'], "{$label}: text");

            foreach (['<b>', '<a href="https://example.org"', '<em>x', '&amp;#', '&amp;lt;', '&amp;amp;', '&amp;quot;'] as $wrong) {
                $this->assertStringNotContainsString($wrong, $mail['html'], "{$label}: html has {$wrong}");
            }
            foreach (['&lt;', '&gt;', '&amp;', '&#'] as $wrong) {
                $this->assertStringNotContainsString($wrong, $mail['text'], "{$label}: text has {$wrong}");
                $this->assertStringNotContainsString($wrong, $mail['subject'], "{$label}: subject has {$wrong}");
            }
            $this->assertDoesNotMatchRegularExpression('/\{[a-z][a-z0-9_]*\}/', $mail['html'].$mail['text'].$mail['subject'], "{$label}: a variable was left unfilled.");
        }
    }

    /** The login link email has no greeting and one button, so it shows all four lines Laravel adds. @return array{subject: string, html: string, text: string} */
    private function loginLinkMail(): array
    {
        $sam = User::query()->firstWhere('email', 'sam@example.org') ?? User::factory()->create(['name' => 'Sam Example', 'email' => 'sam@example.org']);

        return $this->sentMail(new LoginLinkNotification('https://example.org/login/example-token'), $sam);
    }

    public function test_the_every_email_defaults_are_laravels_own_lines(): void
    {
        app('translator')->setLoaded([]);

        $this->assertSame('Hello!', EmailText::defaultText('every_email.heading'));
        $this->assertSame('Regards,', EmailText::defaultText('every_email.sign_off'));
        $this->assertSame('All rights reserved.', EmailText::defaultText('every_email.footer'));
        foreach (LaravelMailLines::KEYS as $laravel => $key) {
            $this->assertSame($laravel, str_replace('{button}', ':actionText', EmailText::defaultText($key)), $key);
        }

        // Unedited, an email reads exactly as Laravel writes it.
        Organization::factory()->create(['name' => 'Example Makers']);
        $mail = $this->loginLinkMail();
        $this->assertStringContainsString('Hello!</h1>', $mail['html']);
        $this->assertStringContainsString("Regards,<br>\nExample Makers", $mail['html']);
        $this->assertStringContainsString('If you\'re having trouble clicking the "Log in" button, copy and paste the URL below'."\n".'into your web browser:', $mail['html']);
        $this->assertStringContainsString('Example Makers. All rights reserved.', $mail['html']);
    }

    public function test_editing_the_sign_off_changes_it_in_a_sent_email_with_the_name_still_under_it(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);
        $this->edit(['every_email.sign_off' => 'Thanks for all you do, <b>truly</b> [x](https://example.org) *team*,']);

        $mail = $this->loginLinkMail();

        $this->assertStringContainsString('Thanks for all you do, &lt;b&gt;truly&lt;/b&gt; [x](https://example.org) *team*,<br>'."\n".'Example Makers', $mail['html']);
        $this->assertStringNotContainsString('Regards,', $mail['html']);
        $this->assertStringNotContainsString('<b>truly', $mail['html']);
        $this->assertStringNotContainsString('<em>', $mail['html']);
        $this->assertStringNotContainsString('<a href="https://example.org"', $mail['html']);
        // The plain-text part takes the <br> out, as it always has.
        $this->assertStringContainsString("Thanks for all you do, <b>truly</b> [x](https://example.org) *team*,\nExample Makers", $mail['text']);
        $this->assertStringNotContainsString('Regards,', $mail['text']);
    }

    public function test_the_edited_button_line_keeps_the_buttons_name_and_address(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);
        $this->edit(['every_email.button_trouble' => 'Can\'t press "{button}"? Copy this address: (not :actionText or :ACTIONTEXT)']);

        $mail = $this->loginLinkMail();

        // A typed :actionText stays as typed rather than becoming the button's name.
        $this->assertStringContainsString('Can\'t press "Log in"? Copy this address: (not :actionText or :ACTIONTEXT)', $mail['html']);
        $this->assertStringContainsString('href="https://example.org/login/example-token"', $mail['html']);
        $this->assertStringContainsString('[https://example.org/login/example-token](https://example.org/login/example-token)', $mail['text']);
        $this->assertStringContainsString('Can\'t press "Log in"? Copy this address: (not :actionText or :ACTIONTEXT)', $mail['text']);
        $this->assertStringNotContainsString('having trouble', $mail['html'].$mail['text']);
    }

    public function test_editing_the_heading_and_footer_changes_them_as_plain_text(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);
        $this->edit(['every_email.heading' => 'Hi <i>there</i> *friend*!', 'every_email.footer' => 'Thanks for volunteering & see you soon!']);

        $mail = $this->loginLinkMail();

        $this->assertStringContainsString('Hi &lt;i&gt;there&lt;/i&gt; *friend*!</h1>', $mail['html']);
        $this->assertStringContainsString('Example Makers. Thanks for volunteering &amp; see you soon!', $mail['html']);
        $this->assertStringNotContainsString('Hello!', $mail['html']);
        $this->assertStringNotContainsString('All rights reserved.', $mail['html']);
        $this->assertStringContainsString('Hi <i>there</i> *friend*!', $mail['text']);
        $this->assertStringContainsString('Example Makers. Thanks for volunteering & see you soon!', $mail['text']);
    }

    public function test_a_stored_footer_with_formatting_marks_sends_the_default_and_is_reported(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);
        Exceptions::fake();
        // The footer goes through Laravel's own escaping, so it can't hold the marks Markdown reads; the editor refuses them, and a row put in by hand is ignored.
        $this->edit(['every_email.footer' => 'Pay [here](//example.org)']);

        $mail = $this->loginLinkMail();

        $this->assertStringContainsString('Example Makers. All rights reserved.', $mail['html']);
        $this->assertStringNotContainsString('Pay', $mail['html'].$mail['text']);
        Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'every_email.footer'));
    }

    public function test_putting_a_line_back_reaches_the_next_email(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);
        $coordinator = User::factory()->create(['email' => 'chris@example.org']);
        app(SaveEmailWording::class)->handle($coordinator, ['every_email.sign_off' => 'Cheers,', 'every_email.heading' => 'Hi!']);

        $this->assertStringContainsString("Cheers,<br>\nExample Makers", $this->loginLinkMail()['html']);

        app(SaveEmailWording::class)->handle($coordinator, ['every_email.sign_off' => null, 'every_email.heading' => null]);

        $mail = $this->loginLinkMail();
        $this->assertStringContainsString("Regards,<br>\nExample Makers", $mail['html']);
        $this->assertStringContainsString('Hello!</h1>', $mail['html']);
        $this->assertStringNotContainsString('Cheers', $mail['html'].$mail['text']);
    }

    public function test_the_lines_laravel_adds_are_left_alone_outside_those_four(): void
    {
        app('translator')->setLoaded([]);
        $this->edit(['every_email.sign_off' => 'Cheers,']);

        $this->assertSame('Not a line we know', __('Not a line we know'));
        $this->assertSame('Cheers,', (string) __('Regards,'));
    }

    /** @return list<string> Footers that spell out a web address or a link with entities, which the plain-text part would decode. */
    private static function entityFooters(): array
    {
        return ['https&#58;//evil.example/x', '&#91;x&#93;(https&#58;&#47;&#47;evil.example)', 'www&#46;evil.example'];
    }

    public function test_a_stored_footer_that_spells_out_an_address_with_entities_sends_the_default(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);

        foreach (self::entityFooters() as $typed) {
            DB::table('email_wordings')->delete();
            $this->edit(['every_email.footer' => $typed]);
            Exceptions::fake();

            $mail = $this->loginLinkMail();

            $this->assertStringContainsString('Example Makers. All rights reserved.', $mail['html'], $typed);
            $this->assertStringContainsString('Example Makers. All rights reserved.', $mail['text'], $typed);
            $this->assertStringNotContainsString('evil.example', $mail['html'].$mail['text'], $typed);
            Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'every_email.footer'));
        }
    }

    public function test_a_stored_footer_gets_every_check_a_save_runs(): void
    {
        app('translator')->setLoaded([]);
        Organization::factory()->create(['name' => 'Example Makers']);

        foreach (['See https://example.org', 'Hi {first_name}', 'Thanks &amp; bye'] as $typed) {
            DB::table('email_wordings')->delete();
            $this->edit(['every_email.footer' => $typed]);
            Exceptions::fake();

            $this->assertStringContainsString('Example Makers. All rights reserved.', $this->loginLinkMail()['html'], $typed);
            Exceptions::assertReported(fn (RuntimeException $e) => str_contains($e->getMessage(), 'every_email.footer'));
        }
    }

    public function test_the_lines_laravel_adds_are_only_used_in_emails(): void
    {
        // An edit of these comes back escaped for Laravel's mail views, so a screen showing one with {{ }} would show entities.
        foreach (File::allFiles(resource_path('views')) as $file) {
            foreach (array_keys(LaravelMailLines::KEYS) as $key) {
                $this->assertStringNotContainsString("'".strtok($key, "\n")."'", $file->getContents(), "{$file->getRelativePathname()} uses Laravel's mail line [{$key}].");
            }
        }
    }
}
