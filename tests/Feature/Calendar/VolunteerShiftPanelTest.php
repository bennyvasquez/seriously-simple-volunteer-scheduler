<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class VolunteerShiftPanelTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Rivera', 'email' => 'sam@example.org']);
    }

    private function shiftOn(string $date, string $start = '18:30', string $end = '21:30', int $needed = 1, int $shadowSpots = 0): Shift
    {
        return Shift::query()->create([
            'area_id' => $this->frontDesk->id,
            'local_date' => $date,
            'starts_at' => CarbonImmutable::parse("{$date} {$start}", self::TIMEZONE)->utc(),
            'ends_at' => CarbonImmutable::parse("{$date} {$end}", self::TIMEZONE)->utc(),
            'people_needed' => $needed,
            'shadow_spots' => $shadowSpots,
        ]);
    }

    private function place(Shift $shift, User $user, AssignmentType $type = AssignmentType::Regular): void
    {
        $shift->assignments()->create(['user_id' => $user->id, 'type' => $type]);
    }

    private function jordan(): User
    {
        return User::factory()->cleared()->create(['name' => 'Jordan Park', 'email' => 'jordan@example.org']);
    }

    private function panel(User $user, Shift $shift, array $query = [])
    {
        return $this->actingAs($user)->get(route('calendar.shift', [$shift] + $query));
    }

    private function coordinator(): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, Role::Coordinator, null);
        $this->enableTwoFactor($user);

        return $user;
    }

    public function test_a_volunteer_sees_names_and_no_editing(): void
    {
        $jordan = $this->jordan();
        $shift = $this->shiftOn('2026-10-22', needed: 2);
        $this->place($shift, $jordan);

        $html = $this->panel($this->sam, $shift)->assertOk()
            ->assertSee('Front desk')->assertSee('Thursday, October 22')->assertSee('One time')
            ->assertSee('Jordan P.')->assertSee('1 of 2 places filled.')
            ->getContent();

        $this->assertStringNotContainsString(route('people.show', $jordan), $html);
        foreach (['Remove from shift', 'End regular', 'Mark no-show', 'Put someone on it', 'Add a regular', 'Cancel this date', 'jordan@example.org', 'Jordan Park'] as $text) {
            $this->assertStringNotContainsString($text, $html, $text);
        }
        $this->assertDoesNotMatchRegularExpression('/>Edit</', $html);
        $this->assertStringNotContainsString('people on it are', $html);
        $this->assertStringNotContainsString('emailed', $html);
        $this->assertStringNotContainsString('role="search"', $html);
    }

    public function test_a_volunteer_sees_no_no_show_form_or_closure_link(): void
    {
        $jordan = $this->jordan();
        $past = $this->shiftOn('2026-10-12');
        $this->place($past, $jordan, AssignmentType::Shadow);
        $html = $this->panel($this->sam, $past)->assertOk()->getContent();
        $this->assertStringNotContainsString('no-show', $html);

        $closure = AreaClosure::query()->create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-22', 'reason' => 'Holiday']);
        $closed = $this->shiftOn('2026-10-22');
        $closed->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Holiday', 'area_closure_id' => $closure->id])->save();
        $html = $this->panel($this->sam, $closed)->assertOk()->assertSee('Cancelled: Holiday')->getContent();
        $this->assertStringNotContainsString(route('calendar.closures.edit', $closure), $html);
    }

    public function test_your_own_row_says_you(): void
    {
        $shift = $this->shiftOn('2026-10-22', needed: 2, shadowSpots: 1);
        $this->place($shift, $this->jordan());
        $this->place($shift, $this->sam);

        $html = $this->panel($this->sam, $shift)->assertOk()->assertSee('You')->getContent();
        $this->assertStringNotContainsString('Sam Rivera', $html);
        $this->assertStringContainsString('<span>You</span><span class="muted">On this shift</span>', $html);
        $this->assertStringContainsString('<span>Jordan P.</span>', $html);
    }

    public function test_take_is_offered_only_when_the_take_page_would_allow_it(): void
    {
        $open = $this->shiftOn('2026-10-22');
        $this->panel($this->sam, $open)->assertOk()->assertSee('Take this shift')->assertSee(route('shifts.take', $open), false);

        $started = $this->shiftOn('2026-10-14', '08:00', '12:00');
        $this->panel($this->sam, $started)->assertOk()->assertDontSee('Take this shift');

        $cancelled = $this->shiftOn('2026-10-23');
        $cancelled->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Closed'])->save();
        $this->panel($this->sam, $cancelled)->assertOk()->assertDontSee('Take this shift');

        $full = $this->shiftOn('2026-10-24');
        $this->place($full, $this->jordan());
        $this->panel($this->sam, $full)->assertOk()->assertDontSee('Take this shift');

        $mine = $this->shiftOn('2026-10-25', '18:00', '20:00');
        $this->place($mine, $this->sam);
        $overlap = $this->shiftOn('2026-10-25', '19:00', '21:00');
        $this->panel($this->sam, $overlap)->assertOk()->assertDontSee('Take this shift');

        $notCleared = User::factory()->create(['cleared_at' => null]);
        $this->panel($notCleared, $open)->assertOk()->assertDontSee('Take this shift');
    }

    public function test_shadow_is_offered_to_someone_not_cleared_on_a_shift_with_a_regular_and_a_spot(): void
    {
        $notCleared = User::factory()->create(['cleared_at' => null]);
        $shift = $this->shiftOn('2026-10-22', shadowSpots: 1);

        $this->panel($notCleared, $shift)->assertOk()->assertDontSee('Shadow this shift');

        $this->place($shift, $this->jordan());
        $this->panel($notCleared, $shift)->assertOk()->assertSee('Shadow this shift')->assertSee(route('shifts.shadow', $shift), false);

        $this->panel($this->sam, $shift)->assertOk()->assertDontSee('Shadow this shift');
    }

    public function test_cant_make_it_is_offered_on_your_own_upcoming_shift(): void
    {
        $shift = $this->shiftOn('2026-10-22');
        $this->place($shift, $this->sam);
        $this->panel($this->sam, $shift)->assertOk()->assertSee("Can't make it")->assertSee(route('shifts.release', $shift), false)->assertDontSee('Take this shift');

        $started = $this->shiftOn('2026-10-14', '08:00', '12:00');
        $this->place($started, $this->sam);
        $this->panel($this->sam, $started)->assertOk()->assertDontSee("Can't make it");
    }

    public function test_panel_query_strings_dont_open_editing_for_a_volunteer(): void
    {
        $jordan = $this->jordan();
        $shift = $this->shiftOn('2026-10-22', needed: 2);
        $this->place($shift, $this->sam);
        $other = User::factory()->cleared()->create(['name' => 'Riley Quinn', 'email' => 'riley@example.org']);

        foreach ([['pick' => 1, 'q' => 'example.org'], ['edit' => 'shift'], ['edit' => 1], ['cancel' => 1], ['restore' => 1], ['confirm' => 1, 'type' => 'regular', 'person' => $other->id]] as $query) {
            $html = $this->panel($this->sam, $shift, $query)->assertOk()->getContent();

            foreach (['riley@example.org', 'jordan@example.org', 'Riley', 'name="start_time"', 'name="q"', 'role="search"', 'anyway', 'Put on shift', 'name="reason"'] as $text) {
                $this->assertStringNotContainsString($text, $html, json_encode($query).' '.$text);
            }
        }

        $this->assertNotNull($jordan);
    }

    public function test_a_coordinators_panel_is_unchanged(): void
    {
        $jordan = $this->jordan();
        $shift = $this->shiftOn('2026-10-22', needed: 2);
        $this->place($shift, $jordan);

        $html = $this->panel($this->coordinator(), $shift)->assertOk()->assertSee('Put someone on it')->assertSee('Remove from shift')->assertSee('Cancel this date')->assertDontSee('Take this shift')->getContent();
        $this->assertMatchesRegularExpression('/>Edit</', $html);
    }
}
