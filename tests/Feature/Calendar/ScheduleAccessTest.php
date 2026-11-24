<?php

namespace Tests\Feature\Calendar;

use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ScheduleAccessTest extends TestCase
{
    use RefreshDatabase;

    private const READ_ROUTES = ['calendar.week', 'calendar.day', 'calendar.month', 'calendar.shift'];

    private Area $area;

    private Shift $shift;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->area = Area::factory()->create();
        $this->shift = Shift::query()->create([
            'area_id' => $this->area->id,
            'local_date' => '2026-10-22',
            'starts_at' => '2026-10-22 16:00:00',
            'ends_at' => '2026-10-22 19:00:00',
            'people_needed' => 1,
        ]);
    }

    private function assertReadPagesOpen(User $user): void
    {
        $this->actingAs($user);

        $this->get(route('calendar.week'))->assertOk();
        $this->get(route('calendar.day'))->assertOk();
        $this->get(route('calendar.month'))->assertOk();
        $this->get(route('calendar.shift', $this->shift))->assertOk();
    }

    public function test_a_volunteer_can_open_the_week_day_month_and_a_shift_panel(): void
    {
        $this->assertReadPagesOpen(User::factory()->cleared()->create());
    }

    public function test_a_not_cleared_volunteer_can_open_the_schedule(): void
    {
        $this->assertReadPagesOpen(User::factory()->create(['cleared_at' => null]));
    }

    public function test_a_volunteer_cant_use_any_editing_route(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->area->id]);
        $regular = SlotRegular::query()->create([
            'weekly_slot_id' => $slot->id,
            'user_id' => User::factory()->create()->id,
            'frequency' => $slot->frequency,
            'starts_on' => '2026-10-01',
        ]);
        $closure = AreaClosure::query()->create(['area_id' => $this->area->id, 'starts_on' => '2026-12-24', 'ends_on' => '2026-12-26', 'reason' => 'Holiday']);

        $parameters = [
            'shift' => $this->shift->id,
            'slot' => $slot->id,
            'regular' => $regular->id,
            'closure' => $closure->id,
            'area' => $this->area->id,
        ];

        $this->actingAs(User::factory()->cleared()->create());

        $checked = 0;
        foreach (Route::getRoutes()->getRoutesByName() as $name => $route) {
            if (! str_starts_with($name, 'calendar.') || in_array($name, self::READ_ROUTES, true)) {
                continue;
            }

            $url = route($name, array_intersect_key($parameters, array_flip($route->parameterNames())));

            foreach ($route->methods() as $method) {
                if (in_array($method, ['HEAD', 'OPTIONS'], true)) {
                    continue;
                }

                $this->call($method, $url)->assertForbidden("{$method} {$name} should refuse a volunteer.");
                $checked++;
            }
        }

        $this->assertGreaterThan(20, $checked);
    }

    public function test_a_turned_off_person_cant_see_the_schedule(): void
    {
        $this->actingAs(User::factory()->deactivated()->create());

        $this->get(route('calendar.week'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
        $this->assertGuest();
    }

    public function test_a_guest_is_sent_to_log_in(): void
    {
        $this->get(route('calendar.week'))->assertRedirect(route('login'));
        $this->get(route('calendar.shift', $this->shift))->assertRedirect(route('login'));
    }
}
