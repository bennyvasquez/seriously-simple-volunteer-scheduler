<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\MarkNoShow;
use App\Cover\ShadowProgress;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\ClearedNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ClearanceScreenTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Area $frontDesk;

    private User $coordinator;

    private User $lead;

    private User $sam;

    private User $jamie;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['timezone' => 'America/Detroit', 'required_shadow_shifts' => 2]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->coordinator = $this->withRole(Role::Coordinator, null, 'Alex Coordinator');
        $this->lead = $this->withRole(Role::AreaLead, $this->frontDesk, 'Casey Lead');
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $this->jamie = User::factory()->create(['name' => 'Jamie Example', 'email' => 'jamie@example.org']);
    }

    private function withRole(Role $role, ?Area $area, string $name): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** A front desk shift on the date, in local time, with Sam as the regular and Jamie shadowing. */
    private function shadowShift(string $date, string $start, string $end, bool $cancelled = false, bool $noShow = false): Assignment
    {
        $startsAt = CarbonImmutable::parse("$date $start", 'America/Detroit');
        $endsAt = CarbonImmutable::parse("$date $end", 'America/Detroit');
        $shift = Shift::create([
            'area_id' => $this->frontDesk->id, 'local_date' => $date,
            'starts_at' => $startsAt->utc(), 'ends_at' => $endsAt->utc(),
            'people_needed' => 1, 'shadow_spots' => 1,
            'cancelled_at' => $cancelled ? now() : null, 'cancel_reason' => $cancelled ? 'Power outage' : null,
        ]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->sam->id, 'type' => AssignmentType::Regular]);
        $assignment = Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->jamie->id, 'type' => AssignmentType::Shadow]);

        if ($noShow) {
            $assignment->forceFill(['no_show_at' => now()])->save();
        }

        return $assignment;
    }

    public function test_ready_for_review_on_the_person_page(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');
        $this->shadowShift('2026-10-06', '15:30', '18:30');
        $this->shadowShift('2026-10-08', '18:30', '21:30', noShow: true);

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertOk()
            ->assertSeeText('Shadowing and clearance')
            ->assertSeeText('Ready for review: 2 of 2 shadow shifts done')
            ->assertSeeText('Required shadow shifts: 2. Coordinators can change this in Settings.')
            ->assertSeeTextInOrder([
                'Thu Oct 8, Front desk 6:30 to 9:30pm, with Sam E. No-show',
                'Tue Oct 6, Front desk 3:30 to 6:30pm, with Sam E. Done',
                'Thu Oct 1, Front desk 6:30 to 9:30pm, with Sam E. Done',
            ])
            ->assertSeeText('Note for the change log (optional)')
            ->assertSee('action="'.route('people.clear', $this->jamie).'"', false)
            ->assertSeeText('Clear for regular shifts');
    }

    public function test_a_cancelled_shadow_shift_shows_as_cancelled(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30', cancelled: true);

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertOk()
            ->assertSeeText('Thu Oct 1, Front desk 6:30 to 9:30pm, with Sam E. Cancelled')
            ->assertSeeText('Shadow shifts done: 0 of 2');
    }

    public function test_not_ready_yet_still_shows_the_button(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertOk()
            ->assertSeeText('Shadow shifts done: 1 of 2')
            ->assertDontSeeText('Ready for review')
            ->assertSeeText('Clear for regular shifts');
    }

    public function test_clearing_from_the_person_page(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');

        $this->actingAs($this->coordinator)->post(route('people.clear', $this->jamie), ['note' => 'Ready early.'])
            ->assertRedirect(route('people.show', $this->jamie))
            ->assertSessionHas('status', 'Jamie E. is cleared for regular shifts.');

        $this->assertTrue($this->jamie->fresh()->isCleared());
        $entry = ChangeLogEntry::query()->where('action', 'user.cleared')->about($this->jamie)->sole();
        $this->assertSame(['via' => 'person_page'], $entry->details);
        $this->assertSame('Ready early.', $entry->changes['note']['new']);
        Notification::assertSentTo($this->jamie, ClearedNotification::class);

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertSeeText('Cleared for regular shifts since Oct 14')
            ->assertDontSeeText('Note for the change log (optional)');
    }

    public function test_clearing_twice_shows_the_refusal(): void
    {
        $this->jamie->forceFill(['cleared_at' => now()])->save();

        $this->actingAs($this->coordinator)->from(route('people.show', $this->jamie))
            ->post(route('people.clear', $this->jamie), ['note' => 'Ready'])
            ->assertRedirect(route('people.show', $this->jamie))
            ->assertSessionHasErrors(['clear' => 'Jamie E. is already cleared.']);
    }

    public function test_a_long_note_is_refused_and_kept(): void
    {
        $note = str_repeat('a', 501);

        $this->actingAs($this->coordinator)->from(route('people.show', $this->jamie))
            ->post(route('people.clear', $this->jamie), ['note' => $note])
            ->assertRedirect(route('people.show', $this->jamie))
            ->assertSessionHasErrors(['clear' => 'Keep the note under 500 characters.'])
            ->assertSessionHasInput('note', $note);

        $this->assertFalse($this->jamie->fresh()->isCleared());
    }

    public function test_a_lead_sees_status_and_history_but_no_button(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');
        $this->shadowShift('2026-10-06', '15:30', '18:30');

        $this->actingAs($this->lead)->get(route('people.show', $this->jamie))
            ->assertOk()
            ->assertSeeText('Ready for review: 2 of 2 shadow shifts done')
            ->assertSeeText('Thu Oct 1, Front desk 6:30 to 9:30pm, with Sam E. Done')
            ->assertDontSeeText('Clear for regular shifts')
            ->assertDontSeeText('Note for the change log (optional)')
            ->assertDontSee(route('people.clear', $this->jamie), false);
    }

    public function test_cleared_people_show_cleared_since(): void
    {
        $this->jamie->forceFill(['cleared_at' => CarbonImmutable::parse('2026-10-03 15:00', 'America/Detroit')])->save();

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertOk()
            ->assertSeeText('Cleared for regular shifts since Oct 3')
            ->assertDontSeeText('Shadow shifts done')
            ->assertDontSeeText('Clear for regular shifts');
    }

    public function test_someone_turned_off_shows_no_button(): void
    {
        $this->jamie->forceFill(['deactivated_at' => now()])->save();

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertOk()
            ->assertSeeText('Shadow shifts done: 0 of 2')
            ->assertDontSeeText('Clear for regular shifts');
    }

    public function test_ready_for_review_in_needs_attention(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');
        $this->shadowShift('2026-10-06', '15:30', '18:30');
        $link = '<a href="'.route('people.show', $this->jamie).'"';

        $this->actingAs($this->coordinator)->get(route('calendar.week'))
            ->assertOk()
            ->assertSeeText('Ready for review: Jamie')
            ->assertSee($link, false);

        $this->actingAs($this->lead)->get(route('calendar.week'))
            ->assertOk()
            ->assertDontSeeText('Ready for review')
            ->assertDontSee($link, false);
    }

    public function test_ready_for_review_comes_after_the_shift_items(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');
        $this->shadowShift('2026-10-06', '15:30', '18:30');
        Shift::create([
            'area_id' => $this->frontDesk->id, 'local_date' => '2026-10-15',
            'starts_at' => CarbonImmutable::parse('2026-10-15 10:00', 'America/Detroit')->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-15 12:00', 'America/Detroit')->utc(),
            'people_needed' => 1, 'shadow_spots' => 0,
        ]);

        $this->actingAs($this->coordinator)->get(route('calendar.week'))
            ->assertOk()
            ->assertSeeTextInOrder(['Needs attention', 'Thu Oct 15, 10am', 'Front desk, 1 open', 'Ready for review: Jamie']);
    }

    public function test_a_no_show_takes_someone_off_ready_for_review(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');
        $second = $this->shadowShift('2026-10-06', '15:30', '18:30');

        $this->assertSame(['Jamie Example'], ShadowProgress::readyForReview()->pluck('name')->all());

        app(MarkNoShow::class)->handle($this->lead, $second, true);

        $this->assertSame([], ShadowProgress::readyForReview()->pluck('name')->all());

        $this->actingAs($this->coordinator)->get(route('calendar.week'))
            ->assertOk()
            ->assertDontSeeText('Ready for review: Jamie');

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertSeeText('Shadow shifts done: 1 of 2')
            ->assertSeeText('Tue Oct 6, Front desk 3:30 to 6:30pm, with Sam E. No-show');
    }

    public function test_the_picker_says_not_cleared_yet(): void
    {
        $shift = Shift::create([
            'area_id' => $this->frontDesk->id, 'local_date' => '2026-10-20',
            'starts_at' => CarbonImmutable::parse('2026-10-20 15:30', 'America/Detroit')->utc(), 'ends_at' => CarbonImmutable::parse('2026-10-20 18:30', 'America/Detroit')->utc(),
            'people_needed' => 2, 'shadow_spots' => 1,
        ]);
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 2, 'start_time' => '15:30', 'end_time' => '18:30']);
        User::factory()->cleared()->create(['name' => 'Jamie Cleared']);

        $shiftPanel = $this->actingAs($this->coordinator)->get(route('calendar.shift', ['shift' => $shift, 'q' => 'Jamie']))->assertOk();
        $shiftPanel->assertSeeText('Jamie Example Not cleared yet');
        $shiftPanel->assertDontSeeText('Jamie Cleared Not cleared yet');

        $addRegular = $this->actingAs($this->coordinator)->get(route('calendar.slots.regulars.create', ['slot' => $slot, 'q' => 'Jamie']))->assertOk();
        $addRegular->assertSeeText('Jamie Example Not cleared yet');
        $addRegular->assertDontSeeText('Jamie Cleared Not cleared yet');
    }

    public function test_shadow_shifts_from_before_a_move_back_say_so(): void
    {
        $this->shadowShift('2026-10-01', '18:30', '21:30');
        $this->shadowShift('2026-10-06', '15:30', '18:30');
        $this->jamie->forceFill(['shadowing_since' => CarbonImmutable::parse('2026-10-07 09:00', 'America/Detroit')])->save();
        $this->shadowShift('2026-10-08', '18:30', '21:30');

        $this->actingAs($this->coordinator)->get(route('people.show', $this->jamie))
            ->assertOk()
            ->assertSeeText('Shadow shifts done: 1 of 2')
            ->assertSeeTextInOrder([
                'Thu Oct 8, Front desk 6:30 to 9:30pm, with Sam E. Done',
                'Tue Oct 6, Front desk 3:30 to 6:30pm, with Sam E. Done (before moving back)',
                'Thu Oct 1, Front desk 6:30 to 9:30pm, with Sam E. Done (before moving back)',
            ]);
    }

    public function test_a_note_that_isnt_text_is_treated_as_no_note(): void
    {
        $this->actingAs($this->coordinator)
            ->post(route('people.clear', $this->jamie), ['note' => ['x']])
            ->assertRedirect(route('people.show', $this->jamie));

        $this->assertTrue($this->jamie->fresh()->isCleared());
        $entry = ChangeLogEntry::query()->where('action', 'user.cleared')->where('subject_id', $this->jamie->id)->sole();
        $this->assertArrayNotHasKey('note', $entry->changes);
    }
}
