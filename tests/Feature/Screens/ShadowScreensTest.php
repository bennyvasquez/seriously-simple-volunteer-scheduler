<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ShadowScreensTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $coordinator;

    private User $sam;

    private User $jordan;

    private User $avery;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['name' => 'Example Makerspace', 'timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->coordinator = User::factory()->create(['name' => 'Chris Coordinator']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $this->jordan = User::factory()->cleared()->create(['name' => 'Jordan Example']);
        $this->avery = User::factory()->create(['name' => 'Avery Trainee']);
    }

    private function shift(string $date, string $from = '18:30', string $to = '21:30', int $shadowSpots = 1, ?User $regular = null): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, $date, $from, $to, 1, $shadowSpots, null);

        if ($regular !== null) {
            $this->on($shift, $regular);
        }

        return $shift;
    }

    /** A shift that has already ended (or is still running when `$endsAt` is ahead), made directly since the actions refuse dates in the past. */
    private function pastShift(string $startsAt, string $endsAt): Shift
    {
        return Shift::create(['area_id' => $this->frontDesk->id, 'local_date' => substr($startsAt, 0, 10), 'starts_at' => CarbonImmutable::parse($startsAt, self::TIMEZONE)->utc(), 'ends_at' => CarbonImmutable::parse($endsAt, self::TIMEZONE)->utc(), 'people_needed' => 1, 'shadow_spots' => 1]);
    }

    private function on(Shift $shift, User $person, AssignmentType $type = AssignmentType::Regular, array $attributes = []): Assignment
    {
        return Assignment::query()->forceCreate(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type] + $attributes);
    }

    public function test_home_for_someone_not_cleared(): void
    {
        // Counted: a shadow shift that has ended. Not counted: a no-show, a cancelled shift, and one still running.
        $this->on($this->pastShift('2026-10-10 18:30', '2026-10-10 21:30'), $this->avery, AssignmentType::Shadow);
        $this->on($this->pastShift('2026-10-11 18:30', '2026-10-11 21:30'), $this->avery, AssignmentType::Shadow, ['no_show_at' => now()]);
        $cancelled = $this->pastShift('2026-10-12 18:30', '2026-10-12 21:30');
        $cancelled->forceFill(['cancelled_at' => now()])->save();
        $this->on($cancelled, $this->avery, AssignmentType::Shadow);
        $this->on($this->pastShift('2026-10-14 08:00', '2026-10-14 12:00'), $this->avery, AssignmentType::Shadow);

        $next = $this->shift('2026-10-15', regular: $this->sam);
        $this->on($next, $this->avery, AssignmentType::Shadow);
        $open = $this->shift('2026-10-16', '10:00', '12:00', regular: $this->jordan);
        $nobody = $this->shift('2026-10-17');

        $response = $this->actingAs($this->avery)->get('/')->assertOk();

        $response->assertSeeInOrder([
            'Hi Avery',
            'Shadow shifts done: 1 of 2',
            'After 2, a coordinator reviews and clears you to take regular shifts.',
            'Your next shadow shift',
            'Thursday, October 15',
            'Shadowing',
            'Front desk, 6:30 to 9:30pm',
            'You\'ll shadow Sam',
            'Shadow spots open',
            'Friday, October 16',
            'Front desk, 10am to 12pm',
            'With Jordan',
            'Shadow this shift',
            'You\'ll be able to take shifts once a coordinator clears you.',
        ], false);
        $response->assertSee('href="'.route('shifts.shadow', $open).'"', false);
        $response->assertSee('href="'.route('shifts.release', $next).'"', false);
        $response->assertDontSee('href="'.route('shifts.shadow', $nobody).'"', false);
        $response->assertDontSee('Open shifts you can take');
        $response->assertDontSee('Take this shift');
    }

    public function test_the_required_number_comes_from_the_settings(): void
    {
        Organization::query()->update(['required_shadow_shifts' => 3]);

        $this->actingAs($this->avery)->get('/')->assertOk()
            ->assertSee('Shadow shifts done: 0 of 3')
            ->assertSee('After 3, a coordinator reviews and clears you to take regular shifts.')
            ->assertSee('No shadow shifts in the next two weeks.', false)
            ->assertSee('No shadow spots open in the next two weeks.');
    }

    public function test_home_for_someone_cleared_is_unchanged(): void
    {
        $this->shift('2026-10-16', '10:00', '12:00', regular: $this->jordan);
        $this->shift('2026-10-17');

        $response = $this->actingAs($this->sam)->get('/')->assertOk();

        $response->assertSee('Your next shifts');
        $response->assertSee('Open shifts you can take');
        $response->assertSee('Take this shift');
        $response->assertDontSee('Shadow shifts done');
        $response->assertDontSee('Shadow spots open');
        $response->assertDontSee('Shadow this shift');
    }

    public function test_the_shadow_page_confirms_and_lands_on_home(): void
    {
        $shift = $this->shift('2026-10-22', regular: $this->sam);
        Notification::fake();

        $page = $this->actingAs($this->avery)->get(route('shifts.shadow', $shift))->assertOk();
        $page->assertSeeInOrder(['Thursday, October 22', 'Front desk, 6:30 to 9:30pm', 'You\'ll shadow Sam'], false);
        $page->assertSee('action="'.route('shifts.shadow', $shift).'"', false);
        $this->assertSame(1, substr_count($page->getContent(), '>Shadow this shift</button>'));

        $this->post(route('shifts.shadow', $shift))
            ->assertRedirect(route('home'))
            ->assertSessionHas('status', 'You\'re shadowing Front desk on Thursday, October 22.');

        $this->assertTrue($shift->assignments()->where('user_id', $this->avery->id)->where('type', AssignmentType::Shadow)->exists());
        $this->get(route('home'))->assertSee('You&#039;re shadowing Front desk on Thursday, October 22.', false);

        // Pressing it twice lands on Home the same way, with one assignment and one entry.
        $this->post(route('shifts.shadow', $shift))->assertRedirect(route('home'))->assertSessionHas('status', 'You\'re shadowing Front desk on Thursday, October 22.');
        $this->assertSame(1, ChangeLogEntry::query()->ofAction('assignment.added')->about($this->avery)->count());
    }

    public function test_the_shadow_page_explains_a_refusal(): void
    {
        $full = $this->shift('2026-10-22', regular: $this->sam);
        $this->on($full, User::factory()->create(['name' => 'Quinn Trainee']), AssignmentType::Shadow);
        $other = $this->shift('2026-10-16', '10:00', '12:00', regular: $this->jordan);

        $page = $this->actingAs($this->avery)->get(route('shifts.shadow', $full))->assertOk();

        $page->assertSeeInOrder(['There&#039;s no shadow spot left on this shift.', 'Thursday, October 22', 'Other shadow spots open', 'Friday, October 16', 'With Jordan'], false);
        $page->assertSee('href="'.route('shifts.shadow', $other).'"', false);
        $page->assertDontSee('action="'.route('shifts.shadow', $full).'"', false);
        $page->assertSee('Back to home');

        // A form posted after the spot went lands on the same explanation.
        $this->post(route('shifts.shadow', $full))->assertRedirect(route('shifts.shadow', $full));
        $this->assertFalse($full->assignments()->where('user_id', $this->avery->id)->exists());
    }

    public function test_someone_cleared_is_sent_to_take_shifts(): void
    {
        $shift = $this->shift('2026-10-22', regular: $this->sam);

        $page = $this->actingAs($this->jordan)->get(route('shifts.shadow', $shift))->assertOk();

        $page->assertSee('Shadow spots are for people who aren&#039;t cleared yet. You can take shifts instead.', false);
        $page->assertDontSee('Other shadow spots open');
    }

    public function test_guests_are_sent_to_log_in(): void
    {
        $shift = $this->shift('2026-10-22', regular: $this->sam);

        $this->get(route('shifts.shadow', $shift))->assertRedirect(route('login'));
        $this->post(route('shifts.shadow', $shift))->assertRedirect(route('login'));
    }
}
