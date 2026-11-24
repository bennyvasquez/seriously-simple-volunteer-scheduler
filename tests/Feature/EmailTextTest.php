<?php

namespace Tests\Feature;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\PutOnShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\ShadowSpotNotice;
use App\Models\Area;
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
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
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
            ->reject(fn (string $class) => in_array($class, [PlainText::class, EmailText::class], true))
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
}
