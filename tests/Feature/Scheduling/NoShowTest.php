<?php

namespace Tests\Feature\Scheduling;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\MarkNoShow;
use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class NoShowTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Area $frontDesk;

    private Area $hostedEvents;

    private User $coordinator;

    private User $lead;

    private User $otherLead;

    private User $avery;

    private User $sam;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, October 14, 2026, 1pm UTC.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 13:00', 'UTC'));
        Organization::factory()->create(['timezone' => 'UTC']);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->hostedEvents = Area::factory()->create(['name' => 'Hosted events']);
        $this->coordinator = $this->withRole('Chris Coordinator', Role::Coordinator);
        $this->lead = $this->withRole('Alex Lead', Role::AreaLead, $this->frontDesk);
        $this->otherLead = $this->withRole('Riley Lead', Role::AreaLead, $this->hostedEvents);
        $this->avery = User::factory()->create(['name' => 'Avery Trainee']);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
    }

    private function withRole(string $name, Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    /** A shift (UTC hours on the given day) with a shadow on it. */
    private function shift(string $starts = '2026-10-14 10:00', string $ends = '2026-10-14 12:00', bool $cancelled = false, AssignmentType $type = AssignmentType::Shadow): Assignment
    {
        $shift = Shift::create([
            'area_id' => $this->frontDesk->id, 'local_date' => substr($starts, 0, 10),
            'starts_at' => CarbonImmutable::parse($starts, 'UTC'), 'ends_at' => CarbonImmutable::parse($ends, 'UTC'),
            'people_needed' => 1, 'shadow_spots' => 1,
            'cancelled_at' => $cancelled ? now() : null, 'cancel_reason' => $cancelled ? 'Power outage' : null,
        ]);

        return Assignment::create(['shift_id' => $shift->id, 'user_id' => ($type === AssignmentType::Shadow ? $this->avery : $this->sam)->id, 'type' => $type]);
    }

    private function mark(User $actor, Assignment $assignment, bool $noShow = true)
    {
        return $this->actingAs($actor)->post(route('calendar.shift.no-show', $assignment->shift_id), ['assignment' => $assignment->id, 'no_show' => $noShow ? 1 : 0]);
    }

    public function test_a_lead_marks_a_no_show_in_their_area_and_it_is_logged(): void
    {
        $assignment = $this->shift();

        $this->mark($this->lead, $assignment)->assertRedirect(route('calendar.shift', $assignment->shift_id))->assertSessionHas('status');

        $this->assertNotNull($assignment->fresh()->no_show_at);
        $entry = ChangeLogEntry::query()->where('action', 'assignment.no_show')->sole();
        $this->assertSame($this->lead->id, $entry->actor_id);
        $this->assertSame('Alex Lead', $entry->actor_name);
        $this->assertSame($this->avery->id, $entry->affected_user_id);
        $this->assertSame($assignment->shift_id, $entry->subject_id);
        $this->assertNull($entry->changes['no_show_at']['old']);
        $this->assertNotNull($entry->changes['no_show_at']['new']);
        $this->assertSame('Marked Avery Trainee as a no-show for the Front desk shift on Wednesday, October 14, 10am to 12pm', Describe::entry($entry, $this->coordinator));
    }

    public function test_undoing_a_no_show_is_logged(): void
    {
        $assignment = $this->shift();
        $this->mark($this->coordinator, $assignment);

        $this->mark($this->coordinator, $assignment, false)->assertSessionHas('status');

        $this->assertNull($assignment->fresh()->no_show_at);
        $entries = ChangeLogEntry::query()->where('action', 'assignment.no_show')->orderBy('id')->get();
        $this->assertCount(2, $entries);
        $this->assertNotNull($entries[1]->changes['no_show_at']['old']);
        $this->assertNull($entries[1]->changes['no_show_at']['new']);
        $this->assertSame('Undid the no-show for Avery Trainee on the Front desk shift on Wednesday, October 14, 10am to 12pm', Describe::entry($entries[1], $this->coordinator));
    }

    public function test_marking_what_is_already_marked_logs_nothing_more(): void
    {
        $assignment = $this->shift();
        $this->mark($this->lead, $assignment);
        $this->mark($this->lead, $assignment);

        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'assignment.no_show')->count());
    }

    public function test_a_lead_of_another_area_gets_403(): void
    {
        $assignment = $this->shift();

        $this->mark($this->otherLead, $assignment)->assertForbidden();
        $this->mark($this->avery, $assignment)->assertForbidden();

        $this->assertNull($assignment->fresh()->no_show_at);
    }

    /** @return array<string, array{0: string}> */
    public static function refusals(): array
    {
        return [['before'], ['regular'], ['cancelled']];
    }

    #[DataProvider('refusals')]
    public function test_refusals(string $case): void
    {
        [$assignment, $message] = match ($case) {
            'before' => [$this->shift('2026-10-14 12:00', '2026-10-14 15:00'), 'You can mark a no-show once the shift has ended.'],
            'regular' => [$this->shift(type: AssignmentType::Regular), 'No-shows are only marked for shadows for now.'],
            'cancelled' => [$this->shift(cancelled: true), 'You can\'t mark a no-show on a cancelled shift.'],
        };

        $this->mark($this->lead, $assignment)->assertRedirect(route('calendar.shift', $assignment->shift_id))->assertSessionHasErrors(['shift' => $message]);

        $this->assertNull($assignment->fresh()->no_show_at);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'assignment.no_show')->count());
    }

    public function test_the_shift_panel_offers_the_button_once_the_shift_has_ended(): void
    {
        $ended = $this->shift();
        $this->actingAs($this->lead)->get(route('calendar.shift', $ended->shift_id))->assertOk()->assertSee('Mark no-show')->assertDontSee('Undo no-show');

        $this->mark($this->lead, $ended);
        $this->actingAs($this->lead)->get(route('calendar.shift', $ended->shift_id))->assertOk()->assertSee('Undo no-show')->assertSee('No-show')->assertDontSee('Mark no-show');

        $future = $this->shift('2026-10-20 10:00', '2026-10-20 12:00');
        $this->actingAs($this->lead)->get(route('calendar.shift', $future->shift_id))->assertOk()->assertDontSee('Mark no-show');
    }

    public function test_the_action_needs_the_policy(): void
    {
        $this->expectException(AuthorizationException::class);

        app(MarkNoShow::class)->handle($this->otherLead, $this->shift(), true);
    }
}
