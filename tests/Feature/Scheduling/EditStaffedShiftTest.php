<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\UpdateShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\ShiftChangedNotification;
use App\Notifications\ShiftTimeChangedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class EditStaffedShiftTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        // The emails are deferred until after the response; calling an action directly has no response, so run them right away.
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->coordinator = User::factory()->create(['name' => 'Coordinator Example']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator, null);
        $this->enableTwoFactor($this->coordinator);
    }

    private function shift(Area $area, string $start, string $end, int $needed = 2, int $shadows = 0): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator, $area, '2026-10-22', $start, $end, $needed, $shadows, null);
    }

    private function person(string $name): User
    {
        return User::factory()->create(['name' => $name, 'email' => strtolower($name).'@example.org']);
    }

    private function putOn(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): void
    {
        app(PutOnShift::class)->handle($this->coordinator, $shift, $person, $type, true);
    }

    public function test_editing_a_staffed_shift_emails_the_people_on_it(): void
    {
        $robin = $this->person('Robin');
        $casey = $this->person('Casey');
        $shift = $this->shift($this->frontDesk, '18:00', '21:00');
        $this->putOn($shift, $robin);
        $this->putOn($shift, $casey);

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '17:00', 'end_time' => '20:00']);

        $entry = ChangeLogEntry::query()->ofAction('shift.updated')->sole();
        $this->assertSame(
            ['old' => CarbonImmutable::parse('2026-10-22 18:00', self::TIMEZONE)->utc()->format('Y-m-d H:i:s'), 'new' => CarbonImmutable::parse('2026-10-22 17:00', self::TIMEZONE)->utc()->format('Y-m-d H:i:s')],
            $entry->changes['starts_at'],
        );

        // A time change asks whether the new time works, with the notice email.
        foreach ([$robin, $casey] as $person) {
            Notification::assertSentTo($person, ShiftTimeChangedNotification::class, fn (ShiftTimeChangedNotification $notification) => $notification->subject === 'Your Front desk shift moved to 5 to 8pm'
                && $notification->lines === ['Thursday, October 22: 5 to 8pm (was 6 to 9pm)']);
        }
        Notification::assertSentTimes(ShiftTimeChangedNotification::class, 2);
        Notification::assertNotSentTo($robin, ShiftChangedNotification::class);
    }

    public function test_a_title_change_is_in_the_email(): void
    {
        $robin = $this->person('Robin');
        $shift = $this->shift($this->frontDesk, '18:00', '21:00');
        $this->putOn($shift, $robin);

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['title' => 'Open house']);

        Notification::assertSentTo($robin, ShiftChangedNotification::class, fn (ShiftChangedNotification $n) => in_array("Your Front desk shift on Thursday, October 22 changed. It's now for Open house.", $n->toMail($robin)->introLines, true));
    }

    public function test_an_edit_that_double_books_someone_is_refused(): void
    {
        $robin = $this->person('Robin');
        $desk = $this->shift($this->frontDesk, '17:00', '19:00');
        $events = $this->shift($this->hostedEvents, '20:00', '22:00');
        $this->putOn($desk, $robin);
        $this->putOn($events, $robin);
        Notification::fake();

        try {
            app(UpdateShift::class)->handle($this->coordinator, $desk, ['start_time' => '19:00', 'end_time' => '21:00']);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['start_time' => ['Robin is on Hosted events, Thursday, October 22, 8 to 10pm at that time.']], $e->errors());
        }

        $this->assertSame('17:00', $desk->fresh()->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('shift.updated')->count());
        Notification::assertNothingSent();
    }

    public function test_lowering_shadow_spots_below_the_shadows_on_it_is_refused(): void
    {
        $shift = $this->shift($this->frontDesk, '18:00', '21:00', 2, 2);
        $this->putOn($shift, $this->person('Robin'), AssignmentType::Shadow);
        $this->putOn($shift, $this->person('Casey'), AssignmentType::Shadow);

        try {
            app(UpdateShift::class)->handle($this->coordinator, $shift, ['shadow_spots' => 1]);
            $this->fail('Expected a ValidationException.');
        } catch (ValidationException $e) {
            $this->assertSame(['shadow_spots' => ['2 people are shadowing this shift. Remove someone from it before lowering it to 1.']], $e->errors());
        }

        $this->assertSame(2, $shift->fresh()->shadow_spots);
    }

    public function test_lowering_people_needed_below_the_people_on_it_is_allowed(): void
    {
        $shift = $this->shift($this->frontDesk, '18:00', '21:00', 3);

        foreach (['Robin', 'Casey', 'Morgan'] as $name) {
            $this->putOn($shift, $this->person($name));
        }

        $this->actingAs($this->coordinator)->followingRedirects()
            ->post(route('calendar.shift.update', $shift), ['start_time' => '18:00', 'end_time' => '21:00', 'people_needed' => 2, 'shadow_spots' => 0, 'title' => ''])
            ->assertOk()
            ->assertSee('Saved the changes to this shift.')
            ->assertSee('3 people on it. It needs 2.');

        $this->assertSame(2, $shift->fresh()->people_needed);
    }

    public function test_changing_only_people_needed_emails_nobody(): void
    {
        $shift = $this->shift($this->frontDesk, '18:00', '21:00', 3);
        $this->putOn($shift, $this->person('Robin'));
        Notification::fake();

        app(UpdateShift::class)->handle($this->coordinator, $shift, ['people_needed' => 2, 'shadow_spots' => 1]);

        $this->assertSame(2, $shift->fresh()->people_needed);
        Notification::assertNothingSent();
    }

    public function test_the_panel_offers_this_shift_even_with_people_on_it(): void
    {
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator, $this->frontDesk, [
            'weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'people_needed' => 2, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
        $shift = $slot->shifts()->orderBy('local_date')->first();
        $this->putOn($shift, $this->person('Robin'));
        $this->putOn($shift, $this->person('Casey'));

        $this->actingAs($this->coordinator)->get(route('calendar.shift', [$shift, 'edit' => 1]))
            ->assertOk()
            ->assertSee("Only Thursday, October 15. Later changes to the weekly slot won't overwrite it. The 2 people on it are emailed if the time or what it's for changes.", false)
            ->assertSee('The weekly slot (future shifts nobody changed on their own)');

        $this->actingAs($this->coordinator)->get(route('calendar.shift', [$shift, 'edit' => 'shift']))->assertOk()->assertSee('Save this shift');
    }

    public function test_only_people_who_can_sign_in_are_counted_and_emailed(): void
    {
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator, $this->frontDesk, [
            'weekday' => 4, 'start_time' => '18:00', 'end_time' => '21:00', 'people_needed' => 2, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
        $shift = $slot->shifts()->orderBy('local_date')->first();
        $robin = $this->person('Robin');
        $casey = User::factory()->notInvited()->create(['name' => 'Casey', 'email' => 'casey@example.org']);
        $this->putOn($shift, $robin);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $casey->id, 'type' => AssignmentType::Regular]);

        $this->actingAs($this->coordinator)->get(route('calendar.shift', [$shift, 'edit' => 1]))
            ->assertOk()
            ->assertSee("The 1 person on it is emailed if the time or what it's for changes.", false);

        Notification::fake();
        app(UpdateShift::class)->handle($this->coordinator, $shift, ['start_time' => '17:00', 'end_time' => '20:00']);

        Notification::assertSentTo($robin, ShiftTimeChangedNotification::class);
        Notification::assertNotSentTo($casey, ShiftTimeChangedNotification::class);
        Notification::assertSentTimes(ShiftTimeChangedNotification::class, 1);
    }

    public function test_the_notification_for_several_shifts(): void
    {
        $desk = $this->shift($this->frontDesk, '17:00', '20:00');
        $events = $this->shift($this->hostedEvents, '19:30', '22:00', 1);
        $robin = $this->person('Robin');
        $notification = new ShiftChangedNotification(collect([
            ['shift' => $desk->load('area'), 'was' => '6 to 9pm', 'wasTitle' => null],
            ['shift' => $events->load('area'), 'was' => '8 to 10pm', 'wasTitle' => null],
        ]));
        $mail = $notification->toMail($robin);

        $this->assertSame('Your shifts changed', $mail->subject);
        $this->assertSame([
            "Your Front desk shift on Thursday, October 22 changed. It's now 5 to 8pm; it was 6 to 9pm.",
            "Your Hosted events shift on Thursday, October 22 changed. It's now 7:30 to 10pm; it was 8 to 10pm.",
            "If you can't make it, let your coordinator know.",
        ], $mail->introLines);
    }

    public function test_a_refused_edit_shows_its_message_beside_start_time(): void
    {
        $robin = $this->person('Robin');
        $desk = $this->shift($this->frontDesk, '17:00', '19:00');
        $events = $this->shift($this->hostedEvents, '20:00', '22:00');
        $this->putOn($desk, $robin);
        $this->putOn($events, $robin);

        $this->actingAs($this->coordinator)->followingRedirects()
            ->post(route('calendar.shift.update', $desk), ['start_time' => '19:00', 'end_time' => '21:00', 'people_needed' => 2, 'shadow_spots' => 0, 'title' => ''])
            ->assertOk()
            ->assertSee('Robin is on Hosted events, Thursday, October 22, 8 to 10pm at that time.')
            ->assertSee('Save this shift');

        $this->assertSame('17:00', $desk->fresh()->starts_at->setTimezone(self::TIMEZONE)->format('H:i'));
    }
}
