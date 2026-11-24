<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class VolunteerScheduleTest extends TestCase
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
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Rivera']);
    }

    private function shiftOn(string $date, string $start = '18:30', string $end = '21:30', int $needed = 1, int $shadowSpots = 0, ?Area $area = null): Shift
    {
        return Shift::query()->create([
            'area_id' => ($area ?? $this->frontDesk)->id,
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
        return User::factory()->cleared()->create(['name' => 'Jordan Park']);
    }

    private function withRole(Role $role, ?Area $area = null, bool $cleared = true): User
    {
        $user = User::factory()->create(['cleared_at' => $cleared ? now() : null]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function week(User $user)
    {
        return $this->actingAs($user)->get(route('calendar.week', ['date' => '2026-10-21']));
    }

    public function test_the_week_page_runs_the_same_number_of_queries_however_many_shifts_it_has(): void
    {
        $first = $this->shiftOn('2026-10-19', needed: 2);
        $this->place($first, $this->jordan());
        $this->place($first, $this->sam);

        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->week($this->sam)->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        // The first request also records when the volunteer was last seen, which is throttled, so measure from the second.
        $count();
        $few = $count();

        foreach (['2026-10-20', '2026-10-21', '2026-10-22', '2026-10-23', '2026-10-24'] as $date) {
            $shift = $this->shiftOn($date, needed: 3);
            $this->place($shift, $this->jordan());
            $this->place($shift, $this->sam);
        }

        $this->assertSame($few, $count());
    }

    public function test_a_volunteer_sees_no_coordinator_controls(): void
    {
        $this->shiftOn('2026-10-22');

        foreach ([route('calendar.week', ['date' => '2026-10-21']), route('calendar.day', ['date' => '2026-10-22'])] as $url) {
            $html = $this->actingAs($this->sam)->get($url)->assertOk()->getContent();

            foreach (['calendar.add', 'calendar.closures.create', 'calendar.areas.create', 'calendar.areas.edit', 'settings'] as $name) {
                $this->assertStringNotContainsString(route($name, $name === 'calendar.areas.edit' ? ['area' => $this->frontDesk] : []), $html, $name);
            }
            $this->assertStringNotContainsString('Needs attention', $html);
            $this->assertStringNotContainsString('+ Add', $html);
            $this->assertStringContainsString('Front desk', $html);
        }
    }

    private function classesOf(string $html): array
    {
        preg_match_all('/<a class="(ev [^"]*)" href="[^"]*calendar\/shifts/', $html, $matches);

        return $matches[1];
    }

    public function test_your_own_shift_says_you_and_has_the_mine_class(): void
    {
        $shift = $this->shiftOn('2026-10-22', needed: 2);
        $this->place($shift, $this->jordan());
        $this->place($shift, $this->sam);

        $html = $this->week($this->sam)->assertOk()->assertSee('<span>You, Jordan P.</span>', false)->getContent();
        // The grid block and the phone list entry.
        $this->assertCount(2, array_filter($this->classesOf($html), fn ($classes) => str_contains($classes, ' mine')));

        // Someone else's view of the same shift has no "You" and no mine class.
        $other = $this->actingAs(User::factory()->cleared()->create())->get(route('calendar.week', ['date' => '2026-10-21']))->assertOk()->assertDontSee('You, ')->getContent();
        $this->assertStringNotContainsString(' mine', implode('|', $this->classesOf($other)));
    }

    public function test_an_open_shift_says_needs_and_is_dashed(): void
    {
        $shift = $this->shiftOn('2026-10-22', needed: 2);
        $this->place($shift, $this->jordan());

        $html = $this->week($this->sam)->assertOk()->assertSee('<span>Jordan P.</span>', false)->assertSee('<span>Needs 1</span>', false)->assertDontSee('1 open')->getContent();
        $this->assertStringContainsString(' open', implode('|', $this->classesOf($html)));
    }

    public function test_an_empty_shift_says_no_one_yet(): void
    {
        $this->shiftOn('2026-10-22');

        $this->week($this->sam)->assertOk()->assertSee('<span>No one yet</span>', false)->assertSee('<span>Needs 1</span>', false)->assertDontSee('No regular');
    }

    public function test_shadow_spot_lines_show_only_to_people_not_cleared(): void
    {
        $withRegular = $this->shiftOn('2026-10-22', shadowSpots: 1);
        $this->place($withRegular, $this->jordan());
        $this->shiftOn('2026-10-23', shadowSpots: 1);

        $notCleared = User::factory()->create(['cleared_at' => null]);

        // On the shift with a regular the spot shows; on the empty one there's nobody to shadow, so it doesn't.
        $html = $this->actingAs($notCleared)->get(route('calendar.week', ['date' => '2026-10-21']))->assertOk()->getContent();
        // Once in the grid and once in the phone list.
        $this->assertSame(2, substr_count($html, '1 shadow spot open'));

        $this->week($this->sam)->assertOk()->assertDontSee('shadow spot open');
    }

    public function test_the_24_hour_border_shows_only_to_people_who_manage_the_area(): void
    {
        $this->shiftOn('2026-10-15', '08:00', '10:00');
        $otherArea = Area::factory()->create(['name' => 'Hosted events']);

        $urgent = fn (User $user) => str_contains(implode('|', $this->classesOf($this->actingAs($user)->get(route('calendar.week', ['date' => '2026-10-14']))->assertOk()->getContent())), 'urgent');

        $this->assertFalse($urgent($this->sam));
        $this->assertFalse($urgent($this->withRole(Role::AreaLead, $otherArea)));
        $this->assertTrue($urgent($this->withRole(Role::AreaLead, $this->frontDesk)));
        $this->assertTrue($urgent($this->withRole(Role::Coordinator)));
    }

    public function test_your_next_shift_is_in_the_sidebar(): void
    {
        $this->place($this->shiftOn('2026-10-13'), $this->sam);
        $later = $this->shiftOn('2026-10-29');
        $soon = $this->shiftOn('2026-10-22', needed: 2);
        $this->place($soon, $this->jordan());
        $this->place($soon, $this->sam, AssignmentType::Shadow);
        $this->place($later, $this->sam);

        $this->week($this->sam)->assertOk()
            ->assertSeeInOrder(['Your next shift', 'Thu Oct 22, 6:30 to 9:30pm', 'Front desk, with Jordan P.'])
            ->assertSee(route('calendar.shift', $soon), false);
    }

    public function test_no_next_shift_says_so(): void
    {
        // A shift that already ended and a cancelled one don't count.
        $this->place($this->shiftOn('2026-10-13'), $this->sam);
        $cancelled = $this->shiftOn('2026-10-22');
        $this->place($cancelled, $this->sam);
        $cancelled->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Closed'])->save();

        $this->week($this->sam)->assertOk()->assertSee("You're not on any shifts yet.", false);
    }

    public function test_the_key_matches_who_is_looking(): void
    {
        $this->shiftOn('2026-10-22');

        $this->week($this->sam)->assertSee('Dashed border: needs someone')->assertSee('Dark border: your shift')->assertDontSee('Thick border');

        $coordinator = $this->withRole(Role::Coordinator);
        $this->week($coordinator)->assertSee('Thick border: open within 24 hours')->assertDontSee('Dark border: your shift');

        $shift = Shift::query()->first();
        $this->place($shift, $coordinator);
        $this->week($coordinator)->assertSee('Dark border: your shift');
    }

    public function test_the_month_view_has_no_add_links_for_a_volunteer(): void
    {
        $this->shiftOn('2026-10-22');

        $html = $this->actingAs($this->sam)->get(route('calendar.month', ['month' => '2026-10']))->assertOk()->getContent();

        $this->assertStringNotContainsString('+ Add', $html);
        $this->assertStringNotContainsString(route('calendar.add'), $html);
        $this->assertStringNotContainsString(route('calendar.closures.create'), $html);
    }

    public function test_short_names_handle_one_word_and_removed_people(): void
    {
        $shift = $this->shiftOn('2026-10-22', needed: 4);
        $this->place($shift, User::factory()->cleared()->create(['name' => 'Cher']));
        $this->place($shift, User::factory()->cleared()->removedPerson()->create());

        $html = $this->week($this->sam)->assertOk()->assertSee('<span>Cher, Removed person</span>', false)->getContent();
        $this->assertStringNotContainsString('Cher.', $html);
        $this->assertStringNotContainsString('Removed person.', $html);

        $this->actingAs($this->sam)->get(route('calendar.shift', $shift))->assertOk()->assertSee('Cher')->assertSee('Removed person')->assertDontSee('Cher.', false);
    }

    public function test_a_coordinator_still_sees_every_control(): void
    {
        $this->shiftOn('2026-10-22');
        $coordinator = $this->withRole(Role::Admin);

        $this->week($coordinator)->assertOk()
            ->assertSee(route('calendar.add', ['date' => '2026-10-21']), false)
            ->assertSee(route('calendar.closures.create'), false)
            ->assertSee(route('calendar.areas.create'), false)
            ->assertSee(route('calendar.areas.edit', $this->frontDesk), false)
            ->assertSee(route('settings'), false)
            ->assertSee('Needs attention')
            ->assertSee('1 open')
            ->assertDontSee('Your next shift');

        $this->actingAs($coordinator)->get(route('calendar.month', ['month' => '2026-10']))->assertOk()->assertSee('+ Add');
    }
}
