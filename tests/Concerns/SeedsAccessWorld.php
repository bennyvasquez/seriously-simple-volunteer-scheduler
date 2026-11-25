<?php

namespace Tests\Concerns;

use App\Actions\Accounts\GrantRole;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;

/**
 * The seeded world the access matrix and the malformed input sweep both use: two areas, people in every role, shifts, a closure and change notices. A test using this trait must be a TestCase, and calls seedWorld() after faking mail and notifications and setting the date.
 */
trait SeedsAccessWorld
{
    use InteractsWithTwoFactor;

    /** @var array<string, mixed> */
    protected array $world = [];

    /** @var array<string, User|null> */
    protected array $visitors = [];

    /** Two areas, a weekly slot with a regular in each, a shift in each, an open shift, a shift every visitor is on, a closure and two change notice batches. */
    protected function seedWorld(): void
    {
        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $areaA = Area::factory()->create(['name' => 'Front desk']);
        $areaB = Area::factory()->create(['name' => 'Wood shop']);

        $this->visitors = [
            'guest' => null,
            'not_invited' => User::factory()->notInvited()->create(['name' => 'Nell Notinvited']),
            'turned_off' => User::factory()->cleared()->deactivated()->create(['name' => 'Toby Turnedoff']),
            'volunteer' => User::factory()->withoutPassword()->create(['name' => 'Vic Volunteer']),
            'cleared' => User::factory()->cleared()->create(['name' => 'Cleo Cleared']),
            'lead_own' => $this->withRole('Lou Lead', Role::AreaLead, $areaA),
            'lead_other' => $this->withRole('Otto Lead', Role::AreaLead, $areaB),
            'coordinator' => $this->withRole('Cora Coordinator', Role::Coordinator),
            'admin' => $this->withRole('Ada Admin', Role::Admin),
            'in_setup' => $this->withRole('Sid Setup', Role::AreaLead, $areaA, finished: false),
        ];

        $regularA = User::factory()->cleared()->create(['name' => 'Robin Regular']);
        $regularB = User::factory()->cleared()->create(['name' => 'Remy Regular']);
        [$slotA, $shiftA, $regularSlotA, $assignmentA] = $this->slotWithRegular($areaA, $regularA);
        $this->slotWithRegular($areaB, $regularB);

        $openShift = $this->shift($areaA, '2026-10-23', peopleNeeded: 1);
        $ownShift = $this->shift($areaA, '2026-10-24', peopleNeeded: 10);

        foreach (array_filter($this->visitors) as $visitor) {
            Assignment::query()->forceCreate(['shift_id' => $ownShift->id, 'user_id' => $visitor->id, 'type' => AssignmentType::Regular]);
            $this->notice('MATRIXOWNBATCH000000000000', $ownShift, $visitor);
        }

        $this->notice('MATRIXOTHERBATCH0000000000', $shiftA, $regularA);

        $this->world = [
            'area_a' => $areaA,
            'slot_a' => $slotA,
            'shift_a' => $shiftA,
            'regular_a' => $regularA,
            'regular_slot_a' => $regularSlotA,
            'regular_a_on_shift_a' => $assignmentA,
            'open_shift' => $openShift,
            'own_shift' => $ownShift,
            'closure_a' => AreaClosure::query()->create(['area_id' => $areaA->id, 'starts_on' => '2026-12-24', 'ends_on' => '2026-12-26', 'reason' => 'Holiday']),
            'own_batch' => 'MATRIXOWNBATCH000000000000',
            'other_batch' => 'MATRIXOTHERBATCH0000000000',
        ];
    }

    protected function withRole(string $name, Role $role, ?Area $area = null, bool $finished = true): User
    {
        $user = User::factory()->cleared()->when(! $finished, fn ($factory) => $factory->withoutPassword())->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);

        if ($finished) {
            $this->enableTwoFactor($user);
        }

        return $user;
    }

    /** @return array{0: WeeklySlot, 1: Shift, 2: SlotRegular, 3: Assignment} */
    protected function slotWithRegular(Area $area, User $person): array
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $area->id, 'shadow_spots' => 1]);
        $regular = SlotRegular::query()->create(['weekly_slot_id' => $slot->id, 'user_id' => $person->id, 'frequency' => $slot->frequency, 'starts_on' => '2026-10-01']);
        $shift = $this->shift($area, '2026-10-22', peopleNeeded: 1, slot: $slot);
        $assignment = Assignment::query()->forceCreate(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => AssignmentType::Regular]);

        return [$slot, $shift, $regular, $assignment];
    }

    /** A shift from 3:30 to 6:30pm in Detroit on the date. */
    protected function shift(Area $area, string $date, int $peopleNeeded, ?WeeklySlot $slot = null): Shift
    {
        return Shift::query()->create([
            'area_id' => $area->id,
            'weekly_slot_id' => $slot?->id,
            'local_date' => $date,
            'starts_at' => CarbonImmutable::parse($date.' 15:30', 'America/Detroit')->utc(),
            'ends_at' => CarbonImmutable::parse($date.' 18:30', 'America/Detroit')->utc(),
            'people_needed' => $peopleNeeded,
            'shadow_spots' => $slot?->shadow_spots ?? 0,
        ]);
    }

    protected function notice(string $batch, Shift $shift, User $person): void
    {
        ChangeNotice::query()->create([
            'batch' => $batch,
            'user_id' => $person->id,
            'shift_id' => $shift->id,
            'changed_by' => $this->visitors['coordinator']->id,
            'was_starts_at' => $shift->starts_at->subHour(),
            'was_ends_at' => $shift->ends_at->subHour(),
        ]);
    }

    /** Swaps @names for the seeded records' keys, inside strings too ("area_lead:@area_a"). */
    protected function resolve(array $values): array
    {
        return array_map(fn ($value) => is_string($value) ? preg_replace_callback('/@([a-z_]+)/', function ($match) {
            $record = $this->world[$match[1]];

            return is_string($record) ? $record : (string) $record->getKey();
        }, $value) : $value, $values);
    }
}
