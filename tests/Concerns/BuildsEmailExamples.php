<?php

namespace Tests\Concerns;

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
use App\Notifications\InvitationNotification;
use App\Notifications\LoginLinkNotification;
use App\Notifications\MadeRegularNotification;
use App\Notifications\MovedBackNotification;
use App\Notifications\NoAnswerLeadNotification;
use App\Notifications\PasswordChangedNotification;
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
use App\Notifications\TwoFactorAttemptsNotification;
use App\Notifications\TwoFactorCodeNotification;
use App\Notifications\TwoFactorResetNotification;
use Carbon\CarbonImmutable;

/** Builds one example of every email and sends it through the real mail channel, for tests that check what every email says. */
trait BuildsEmailExamples
{
    private const TIMEZONE = 'America/Detroit';

    /**
     * One example of each email class (more where a class reads very differently, such as one shift or several), keyed by a label for failure messages. Built from a small example schedule: a Front desk area with one-time shifts and a weekly Thursday slot.
     *
     * Given `$name`, every person's name and every name passed to an email is that instead. Given `$text`, so is every area name, title, reason and the organization's name. Both are stored as given, without the checks a form runs.
     *
     * @return array<string, array{0: object, 1: User}>
     */
    protected function emailExamples(?string $name = null, ?string $text = null): array
    {
        $hostileName = $name;
        $hostileText = $text;
        $name = fn (string $example): string => $hostileName ?? $example;
        $text = fn (string $example): string => $hostileText ?? $example;
        $this->travelTo(CarbonImmutable::parse('2026-10-19 08:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8, 'name' => $text('Example Makers')]);
        $area = Area::factory()->create(['name' => $text('Front desk')]);
        $coordinator = User::factory()->cleared()->create(['name' => $name('Chris Coordinator'), 'email' => 'chris@example.org']);
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator, null);
        $sam = User::factory()->cleared()->create(['name' => $name('Sam Example'), 'email' => 'sam@example.org']);
        $jordan = User::factory()->cleared()->create(['name' => $name('Jordan Pat'), 'email' => 'jordan@example.org']);
        $casey = User::factory()->cleared()->create(['name' => $name('Casey Shadow'), 'email' => 'casey@example.org']);

        $shiftOn = function (string $date, string $from = '18:30', string $to = '21:30', ?string $title = null, int $needed = 2) use ($coordinator, $area): Shift {
            return app(CreateOneTimeShift::class)->handle($coordinator, $area, $date, $from, $to, $needed, 1, $title)->load('area');
        };
        $first = $shiftOn('2026-10-22');
        $second = $shiftOn('2026-10-29');
        $titled = $shiftOn('2026-11-05', '18:30', '21:30', $text('Open house'));
        $both = collect([$first, $second]);
        $roster = $shiftOn('2026-10-21', '09:00', '12:00', $text('Open house'));
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
            'invitation by a coordinator' => [new InvitationNotification($url, $name('Chris C.')), $sam],
            'two factor reset' => [new TwoFactorResetNotification($coordinator), $sam],
            'two factor attempts' => [new TwoFactorAttemptsNotification, $sam],
            'password changed' => [new PasswordChangedNotification, $sam],
            'put on shift' => [new PutOnShiftNotification($first), $sam],
            'cleared' => [new ClearedNotification($coordinator, Organization::current()), $sam],
            'removed from one shift' => [new RemovedFromShiftsNotification(collect([$first])), $sam],
            'removed from several shifts' => [new RemovedFromShiftsNotification($both), $sam],
            'shadow signed up, nobody else' => [new ShadowSignedUpNotification($first, []), $casey],
            'shadow signed up, one regular' => [new ShadowSignedUpNotification($first, [$name('Sam E.')]), $casey],
            'shadow signed up, two regulars' => [new ShadowSignedUpNotification($first, [$name('Sam E.'), $name('Jordan P.')]), $casey],
            'shadow signed up, to a lead' => [new ShadowSignedUpLeadNotification($first, $name('Casey S.'), [$name('Sam E.'), $name('Jordan P.')]), $coordinator],
            'shadow signed up, to a lead, nobody else' => [new ShadowSignedUpLeadNotification($first, $name('Casey S.'), []), $coordinator],
            'made regular, no shifts' => [new MadeRegularNotification($slot->load('area'), $regular, 0), $sam],
            'made regular, one shift' => [new MadeRegularNotification($slot->load('area'), $regular, 1), $sam],
            'made regular, several shifts' => [new MadeRegularNotification($slot->load('area'), $regular, 3), $sam],
            'regular ending' => [new RegularEndedNotification($slot->load('area'), '2026-11-05', $slotRun), $sam],
            'regular ended' => [new RegularEndedNotification($slot->load('area'), '2026-10-01', collect()), $sam],
            'shadow left, no note' => [new ShadowLeftNotification($name('Casey S.'), collect([$first]), null), $coordinator],
            'shadow left, with note' => [new ShadowLeftNotification($name('Casey S.'), $both, 'Sick this week.'), $coordinator],
            'moved back, nothing released' => [new MovedBackNotification($coordinator, Organization::current(), 2, collect(), []), $sam],
            'moved back, one shift' => [new MovedBackNotification($coordinator, Organization::current(), 1, collect([$first]), []), $sam],
            'moved back, shifts and a slot' => [new MovedBackNotification($coordinator, Organization::current(), 3, $both, [$text('Thursdays, Front desk')], [$second->id]), $sam],
            'shift changed, time' => [new ShiftChangedNotification(collect([$change($first, '5 to 8pm')])), $sam],
            'shift changed, title' => [new ShiftChangedNotification(collect([$change($titled, '6:30 to 9:30pm', null)])), $sam],
            'shift changed, several' => [new ShiftChangedNotification(collect([$change($first, '5 to 8pm'), $change($titled, '6:30 to 9:30pm', $text('Old title'))])), $sam],
            'shift cancelled, one' => [new ShiftCancelledNotification(collect([$first]), $text('Building closed')), $sam],
            'shift cancelled, several' => [new ShiftCancelledNotification($both, $text('Building closed.')), $sam],
            'shift cancelled, with restored' => [new ShiftCancelledNotification(collect([$first]), $text('Building closed'), collect([$second])), $sam],
            'shift restored, one' => [new ShiftRestoredNotification($first), $sam],
            'shift restored, several' => [new ShiftRestoredNotification($both), $sam],
            'shifts released, one' => [new ShiftsReleasedNotification(collect([$first])), $sam],
            'shifts released, one with shadows and regular' => [new ShiftsReleasedNotification(collect([$first]), [$first->id => [$name('Casey S.')]], [$first->id], [$first->id]), $sam],
            'shifts released, one nobody asked' => [new ShiftsReleasedNotification(collect([$first]), [], [], [], null, [$first->id]), $sam],
            'shifts released, several' => [new ShiftsReleasedNotification($both, [$first->id => [$name('Casey S.'), $name('Alex R.')]], [$second->id], [], null, [$first->id]), $sam],
            'shifts released, gave up slot' => [new ShiftsReleasedNotification($both, [], [], [], ['weekday' => 'Thursday', 'from' => 'Thursday, October 29']), $sam],
            'shifts released, gave up slot, none on calendar' => [new ShiftsReleasedNotification(collect(), [], [], [], ['weekday' => 'Thursday', 'from' => 'Thursday, October 29']), $sam],
            'cover request, one' => [new CoverRequestNotification(collect([$first]), $name('Sam'), null), $jordan],
            'cover request, several with note' => [new CoverRequestNotification($both, $name('Sam'), 'Sorry, thanks <b>so much</b>!'), $jordan],
            'cover request, still open' => [new CoverRequestNotification(collect([$first]), $name('Sam'), null, true), $jordan],
            'cover request, gave up slot' => [new CoverRequestNotification($both, $name('Sam'), null, false, true), $jordan],
            'cover urgent, one' => [new CoverUrgentNotification($first, $name('Sam'), $releasedAt, 4, false, $text('a shift lead for Front desk')), $coordinator],
            'cover urgent, resent' => [new CoverUrgentNotification($first, $name('Sam'), $releasedAt, 1, true, $text('a coordinator')), $coordinator],
            'cover urgent, several' => [(new CoverUrgentNotification($first, $name('Sam'), $releasedAt, 4, false, $text('a shift lead for Front desk')))->merge(new CoverUrgentNotification($second, $name('Jordan'), $releasedAt, 0, true, $text('a coordinator'))), $coordinator],
            'shadow spot on hold, one' => [new ShadowSpotNotification($first, ShadowSpotNotice::OnHold, $name('Sam')), $casey],
            'shadow spot on hold, several' => [(new ShadowSpotNotification($first, ShadowSpotNotice::OnHold, $name('Sam')))->merge(new ShadowSpotNotification($second, ShadowSpotNotice::OnHold, $name('Sam'))), $casey],
            'shadow spot back on, one' => [new ShadowSpotNotification($first, ShadowSpotNotice::BackOn, $name('Sam'), $name('Jordan P.')), $casey],
            'shadow spot back on, several' => [(new ShadowSpotNotification($first, ShadowSpotNotice::BackOn, $name('Sam'), $name('Jordan P.')))->merge(new ShadowSpotNotification($second, ShadowSpotNotice::BackOn, $name('Sam'), $name('Jordan P.'))), $casey],
            'shadow spot still uncovered, one' => [new ShadowSpotNotification($first, ShadowSpotNotice::StillUncovered, $name('Sam')), $casey],
            'shadow spot still uncovered, one with a lead' => [new ShadowSpotNotification($first, ShadowSpotNotice::StillUncovered, $name('Sam'), null, $name('Alex (Shift lead)')), $casey],
            'shadow spot still uncovered, several with leads' => [(new ShadowSpotNotification($first, ShadowSpotNotice::StillUncovered, $name('Sam'), null, $name('Alex (Shift lead)')))->merge(new ShadowSpotNotification($second, ShadowSpotNotice::StillUncovered, $name('Sam'), null, $name('Jo (Shift lead)'))), $casey],
            'shift taken, cover' => [new ShiftTakenNotification($first, $name('Sam')), $jordan],
            'shift taken, cover with note and shadows' => [new ShiftTakenNotification($first, $name('Sam'), 'Thanks <b>so much</b>', [$name('Casey S.'), $name('Alex R.')]), $jordan],
            'shift taken, one shadow' => [new ShiftTakenNotification($first, $name('Sam'), null, [$name('Casey S.')]), $jordan],
            'shift taken, took back' => [new ShiftTakenNotification($first, null, null, [], true), $sam],
            'shift taken, regular' => [new ShiftTakenNotification($first, $name('Sam'), null, [], false, 'Thursday', ['Thursday, October 29'], ['Thursday, November 5']), $jordan],
            'shift taken, open shift' => [new ShiftTakenNotification($first), $jordan],
            'shift taken, to a lead, covering' => [new ShiftTakenLeadNotification($first, $name('Jordan P.'), $name('Sam')), $coordinator],
            'shift taken, to a lead, took back' => [new ShiftTakenLeadNotification($first, $name('Sam E.'), $name('Sam'), true), $coordinator],
            'shift taken, to a lead, regular' => [new ShiftTakenLeadNotification($first, $name('Jordan P.'), $name('Sam'), false, 'Thursday'), $coordinator],
            'shift taken, to a lead, open place' => [new ShiftTakenLeadNotification($first, $name('Jordan P.')), $coordinator],
            'shift covered, one' => [new ShiftCoveredNotification($first, $name('Jordan')), $sam],
            'shift covered, several' => [(new ShiftCoveredNotification($first, $name('Jordan')))->merge(new ShiftCoveredNotification($second, $name('Jordan'))), $sam],
        ];

        $tail = [
            // Several shifts of one slot, in a row: reads as a range.
            'time changed, a run of one slot' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', $slotRun->map($moved), $name('Chris C.')), $sam],
            'time changed, one shift' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first)]), $name('Chris C.')), $sam],
            'time changed, one shift, by the system' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first)]), null), $sam],
            'time changed, two separate shifts' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first), $moved($roster)]), $name('Chris C.')), $sam],
            'time changed, with a title change' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($titled) + ['was_title' => $text('Old title')]]), $name('Chris C.')), $sam],
            'time changed, back on, one' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($first) + ['back_on' => true]]), $name('Chris C.')), $sam],
            'time changed, back on, several' => [new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', $both->map(fn (Shift $shift) => $moved($shift) + ['back_on' => true]), $name('Chris C.')), $sam],
            'time changed, starting within 48 hours, with more lines' => [(new ShiftTimeChangedNotification('01HZZZZZZZZZZZZZZZZZZZZZZZ', collect([$moved($emptyShadowShift)]), $name('Chris C.')))->withTitleChanges(collect([$change($titled, '6:30 to 9:30pm', $text('Old title'))]))->withMoreLines(['Your Front desk shift on Thursday, October 29 is cancelled.']), $sam],
            'no answer yet' => [new NoAnswerLeadNotification($first, [$name('Sam E.'), $name('Jordan P.')], '5 to 8pm', $text('a shift lead for Front desk')), $coordinator],
            'no answer yet, one person' => [new NoAnswerLeadNotification($first, [$name('Sam E.')], '5 to 8pm', $text('a coordinator')), $coordinator],
            'reminder, regular with others' => [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), [$name('Jordan P.')], [$name('Casey S.')], null), $sam],
            'reminder, regular alone' => [new ShiftReminderNotification($first, app(PutOnShift::class)->handle($coordinator, $first, $jordan, AssignmentType::Regular), [], [], null), $jordan],
            'reminder, shadow with regulars' => [new ShiftReminderNotification($roster, $rosterAssignment, [$name('Sam E.'), $name('Jordan P.')], [], null), $casey],
            'reminder, shadow with nobody to shadow' => [new ShiftReminderNotification($emptyShadowShift, $emptyShadowAssignment, [], [], null), $casey],
            'reminder, with an open time change' => [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), [$name('Jordan P.')], [], '01HZZZZZZZZZZZZZZZZZZZZZZZ'), $sam],
        ];

        // Today and tomorrow read differently from a date, so the reminder is built on each.
        $examples = $examples + $tail;
        $this->travelTo(CarbonImmutable::parse('2026-10-20 08:00', self::TIMEZONE));
        $examples['reminder, tomorrow'] = [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), [$name('Jordan P.')], [], null), $sam];
        $this->travelTo(CarbonImmutable::parse('2026-10-21 07:00', self::TIMEZONE));
        $examples['reminder, today'] = [new ShiftReminderNotification($roster, $roster->assignments()->where('user_id', $sam->id)->firstOrFail(), [$name('Jordan P.')], [], null), $sam];

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
}
