<?php

namespace Tests\Feature\Cover;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Cover\CoverEligibility;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\CoverRequest;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class CoverEligibilityTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->coordinator = User::factory()->create(['name' => 'Alex Coordinator']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
    }

    private function shift(string $date = '2026-10-22', string $start = '18:30', string $end = '21:30', ?Area $area = null): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator, $area ?? $this->frontDesk, $date, $start, $end, 2, 1, null);
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular): void
    {
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
    }

    public function test_recipients_leave_out_people_who_cant_take_it(): void
    {
        $shift = $this->shift();
        $turnedOff = User::factory()->deactivated()->create(['name' => 'Taylor Off']);
        $notInvited = User::factory()->notInvited()->create(['name' => 'Jordan New']);
        $emailsOff = User::factory()->create(['name' => 'Casey Quiet', 'cover_request_emails' => false]);
        $onIt = User::factory()->create(['name' => 'Sam Regular']);
        $shadowing = User::factory()->create(['name' => 'Kim Shadow']);
        $overlapping = User::factory()->create(['name' => 'Pat Busy']);
        $backToBack = User::factory()->create(['name' => 'Lee Early']);
        $free = User::factory()->create(['name' => 'Morgan Free']);

        $this->on($shift, $onIt);
        $this->on($shift, $shadowing, AssignmentType::Shadow);
        $this->on($this->shift('2026-10-22', '19:00', '21:00', $this->hostedEvents), $overlapping);
        $this->on($this->shift('2026-10-22', '15:30', '18:30', $this->hostedEvents), $backToBack);

        $ids = CoverEligibility::recipients($shift)->pluck('id')->all();

        $this->assertSame([$this->coordinator->id, $backToBack->id, $free->id], $ids);
        foreach ([$turnedOff, $notInvited, $emailsOff, $onIt, $shadowing, $overlapping] as $person) {
            $this->assertNotContains($person->id, $ids, $person->name.' should not get the cover request.');
        }
    }

    public function test_recipients_leave_out_whoever_released_the_shift(): void
    {
        $shift = $this->shift();
        $robin = User::factory()->create(['name' => 'Robin Example']);
        $free = User::factory()->create(['name' => 'Morgan Free']);
        CoverRequest::create(['shift_id' => $shift->id, 'released_by' => $robin->id, 'notified_count' => 2]);

        $this->assertSame([$this->coordinator->id, $free->id], CoverEligibility::recipients($shift)->pluck('id')->all());
    }

    public function test_an_overlapping_shift_that_was_cancelled_doesnt_count(): void
    {
        $shift = $this->shift();
        $person = User::factory()->create(['name' => 'Pat Busy']);
        $other = $this->shift('2026-10-22', '19:00', '21:00', $this->hostedEvents);
        $this->on($other, $person);
        $other->forceFill(['cancelled_at' => now()])->save();

        $this->assertContains($person->id, CoverEligibility::recipients($shift)->pluck('id')->all());
        $this->assertNull(CoverEligibility::refusal($person, $shift));
    }

    public function test_someone_who_can_take_it_gets_no_refusal(): void
    {
        $this->assertNull(CoverEligibility::refusal(User::factory()->create(), $this->shift()));
    }

    public function test_refuses_a_cancelled_shift(): void
    {
        $shift = $this->shift();
        $shift->forceFill(['cancelled_at' => now()])->save();

        $this->assertSame('This shift was cancelled.', CoverEligibility::refusal(User::factory()->create(), $shift));
    }

    public function test_refuses_a_shift_that_has_started(): void
    {
        $shift = $this->shift();
        $this->travelTo(CarbonImmutable::parse('2026-10-22 18:30', self::TIMEZONE));

        $this->assertSame('This shift has already started.', CoverEligibility::refusal(User::factory()->create(), $shift));
    }

    public function test_refuses_someone_already_on_it(): void
    {
        $shift = $this->shift();
        $person = User::factory()->create();
        $this->on($shift, $person);

        $this->assertSame('You\'re already on this shift.', CoverEligibility::refusal($person, $shift));
    }

    public function test_refuses_someone_shadowing_it(): void
    {
        $shift = $this->shift();
        $person = User::factory()->create();
        $this->on($shift, $person, AssignmentType::Shadow);

        $this->assertSame('You\'re shadowing this shift.', CoverEligibility::refusal($person, $shift));
    }

    public function test_refuses_someone_on_an_overlapping_shift(): void
    {
        $shift = $this->shift();
        $person = User::factory()->create();
        $this->on($this->shift('2026-10-22', '19:00', '21:00', $this->hostedEvents), $person);

        $this->assertSame('You\'re already on Hosted events, Thursday, October 22, 7 to 9pm.', CoverEligibility::refusal($person, $shift));
    }
}
