<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SlotEditFromDateTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private User $coordinator;

    private WeeklySlot $slot;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $area = Area::factory()->create(['name' => 'Front desk']);
        $this->coordinator = User::factory()->create(['name' => 'Coordinator Example']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator, null);
        // Thursdays, 6:30 to 9:30pm.
        $this->slot = WeeklySlot::factory()->create(['area_id' => $area->id, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 2]);
        app(GenerateShifts::class)->handle(Organization::current());
    }

    private function shiftOn(string $date): Shift
    {
        return Shift::query()->where('weekly_slot_id', $this->slot->id)->whereDate('local_date', $date)->firstOrFail();
    }

    private function startOn(string $date): string
    {
        return $this->shiftOn($date)->starts_at->setTimezone(self::TIMEZONE)->format('H:i');
    }

    private function attributes(array $changes = []): array
    {
        return $changes + [
            'weekday' => $this->slot->weekday, 'start_time' => '18:30', 'end_time' => '21:30',
            'people_needed' => 2, 'shadow_spots' => 0, 'frequency' => $this->slot->frequency->value,
            'valid_from' => $this->slot->valid_from->toDateString(), 'valid_until' => $this->slot->valid_until?->toDateString(),
            'title' => null, 'is_rotation' => false,
        ];
    }

    private function edit(array $changes, ?string $from): void
    {
        app(UpdateWeeklySlot::class)->handle($this->coordinator, $this->slot->fresh(), $this->attributes($changes), $from === null ? null : CarbonImmutable::parse($from, self::TIMEZONE));
    }

    public function test_shifts_before_the_start_date_keep_their_details(): void
    {
        $this->edit(['start_time' => '17:00', 'end_time' => '20:00'], '2026-10-29');

        $this->assertSame('18:30', $this->startOn('2026-10-15'));
        $this->assertSame('18:30', $this->startOn('2026-10-22'));
    }

    public function test_shifts_from_the_start_date_change(): void
    {
        $this->edit(['start_time' => '17:00', 'end_time' => '20:00'], '2026-10-29');

        $this->assertSame('17:00', $this->startOn('2026-10-29'));
        $this->assertSame('17:00', $this->startOn('2026-11-05'));
        $this->assertSame('17:00', $this->slot->fresh()->start_time === '17:00:00' ? '17:00' : 'not saved');
    }

    public function test_earlier_shifts_keep_their_details_after_a_later_slot_edit(): void
    {
        $this->edit(['start_time' => '17:00', 'end_time' => '20:00'], '2026-10-29');
        $this->edit(['start_time' => '16:00', 'end_time' => '19:00'], null);

        $this->assertSame('18:30', $this->startOn('2026-10-15'));
        $this->assertSame('18:30', $this->startOn('2026-10-22'));
        $this->assertSame('16:00', $this->startOn('2026-10-29'));
    }

    public function test_a_start_date_in_the_past_is_treated_as_today(): void
    {
        $this->edit(['start_time' => '17:00', 'end_time' => '20:00'], '2026-10-01');

        $this->assertSame('17:00', $this->startOn('2026-10-15'));
        $this->assertNull($this->shiftOn('2026-10-15')->edited_at);
    }

    public function test_day_and_repeat_changes_are_still_refused_with_regulars(): void
    {
        app(AddRegular::class)->handle(null, $this->slot, User::factory()->create(), '2026-10-15', '2026-10-29');

        $this->expectException(DomainException::class);

        $this->edit(['weekday' => 3], '2026-10-22');
    }

    public function test_only_shifts_from_the_start_date_get_notices(): void
    {
        $sam = User::factory()->create();
        app(AddRegular::class)->handle(null, $this->slot, $sam, '2026-10-15', '2026-10-29');

        $this->edit(['start_time' => '17:00', 'end_time' => '20:00'], '2026-10-22');

        $shiftIds = ChangeNotice::query()->where('user_id', $sam->id)->pluck('shift_id')->all();
        sort($shiftIds);
        $expected = [$this->shiftOn('2026-10-22')->id, $this->shiftOn('2026-10-29')->id];
        sort($expected);
        $this->assertSame($expected, $shiftIds);
    }

    public function test_the_marked_earlier_shifts_are_logged(): void
    {
        $this->edit(['start_time' => '17:00', 'end_time' => '20:00'], '2026-10-22');

        $earlier = $this->shiftOn('2026-10-15');
        $this->assertNotNull($earlier->edited_at);
        $entry = ChangeLogEntry::query()->forSubject($earlier)->ofAction('shift.updated')->sole();
        $this->assertSame('slot.updated_from', $entry->details['via']);
        $this->assertSame($this->slot->id, $entry->details['weekly_slot_id']);
        // No time, title or count changed: the only field is the mark itself.
        $this->assertSame(['edited_at'], array_keys($entry->changes));
        $this->assertNull($this->shiftOn('2026-10-22')->edited_at);
    }
}
