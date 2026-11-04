<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateArea;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\UpdateArea;
use App\Actions\UpdateSettings;
use App\Enums\AreaColor;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class AreasAndSettingsTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['name' => 'Example Makerspace', 'timezone' => 'America/Detroit', 'weeks_ahead' => 2]);
    }

    private function withRole(Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function coordinator(): User
    {
        return $this->withRole(Role::Coordinator);
    }

    public function test_area_names_are_unique_and_colors_are_given_in_order(): void
    {
        $first = app(CreateArea::class)->handle($this->coordinator(), 'Front desk');
        $second = app(CreateArea::class)->handle($this->coordinator(), 'Hosted events');

        $this->assertNotSame($first->color, $second->color);
        $this->assertSame(AreaColor::cases()[0], $first->color);
        $this->assertSame(AreaColor::cases()[1], $second->color);

        try {
            app(CreateArea::class)->handle($this->coordinator(), 'front desk');
            $this->fail('A duplicate name should be refused.');
        } catch (ValidationException $e) {
            $this->assertSame("There's already an area called Front desk.", $e->errors()['name'][0]);
        }

        $this->assertSame(2, Area::count());
    }

    public function test_creating_an_area_logs_it_and_needs_a_coordinator(): void
    {
        $actor = $this->coordinator();
        $area = app(CreateArea::class)->handle($actor, '  Front desk ');

        $this->assertSame('Front desk', $area->name);
        $entry = ChangeLogEntry::query()->forSubject($area)->ofAction('area.created')->sole();
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame('Front desk', $entry->changes['name']['new']);

        $this->expectException(AuthorizationException::class);
        app(CreateArea::class)->handle($this->withRole(Role::AreaLead, Area::factory()->create()), 'Other');
    }

    public function test_updating_an_area_renames_recolors_and_logs(): void
    {
        $actor = $this->coordinator();
        $area = app(CreateArea::class)->handle($actor, 'Front desk');
        $other = app(CreateArea::class)->handle($actor, 'Hosted events');

        $updated = app(UpdateArea::class)->handle($actor, $area, 'Reception', AreaColor::Purple);

        $this->assertSame('Reception', $updated->fresh()->name);
        $this->assertSame(AreaColor::Purple, $updated->fresh()->color);
        $entry = ChangeLogEntry::query()->forSubject($area)->ofAction('area.updated')->sole();
        $this->assertSame(['old' => 'Front desk', 'new' => 'Reception'], $entry->changes['name']);
        $this->assertSame('purple', $entry->changes['color']['new']);

        // Keeping its own name (even in different case) is fine; another area's name is not.
        app(UpdateArea::class)->handle($actor, $area, 'RECEPTION', AreaColor::Purple);

        try {
            app(UpdateArea::class)->handle($actor, $area, 'hosted EVENTS', AreaColor::Purple);
            $this->fail('A duplicate name should be refused.');
        } catch (ValidationException $e) {
            $this->assertSame("There's already an area called Hosted events.", $e->errors()['name'][0]);
        }

        $this->assertSame($other->name, $other->fresh()->name);
    }

    public function test_a_save_that_changes_nothing_logs_nothing(): void
    {
        $actor = $this->coordinator();
        $area = app(CreateArea::class)->handle($actor, 'Front desk');

        app(UpdateArea::class)->handle($actor, $area, 'Front desk', $area->color);

        $this->assertSame(0, ChangeLogEntry::query()->forSubject($area)->ofAction('area.updated')->count());
    }

    public function test_the_area_screens_work_and_are_for_coordinators(): void
    {
        $coordinator = $this->coordinator();

        $this->actingAs($coordinator)->get(route('calendar.areas.create'))->assertOk()->assertSee('Add an area');
        $this->actingAs($coordinator)->post(route('calendar.areas.store'), ['name' => 'Front desk'])->assertRedirect();
        $area = Area::query()->where('name', 'Front desk')->firstOrFail();

        $this->actingAs($coordinator)->get(route('calendar.areas.edit', $area))->assertOk()->assertSee('Front desk');
        $this->actingAs($coordinator)->post(route('calendar.areas.update', $area), ['name' => 'Reception', 'color' => 'teal'])->assertRedirect();
        $this->assertSame('Reception', $area->fresh()->name);

        $this->actingAs($coordinator)->post(route('calendar.areas.store'), ['name' => 'reception'])
            ->assertSessionHasErrors(['name' => "There's already an area called Reception."]);
        $this->actingAs($coordinator)->post(route('calendar.areas.store'), ['name' => ''])->assertSessionHasErrors('name');
        $this->actingAs($coordinator)->post(route('calendar.areas.update', $area), ['name' => 'Reception', 'color' => 'pink'])->assertSessionHasErrors('color');

        $lead = $this->withRole(Role::AreaLead, $area);
        $this->actingAs($lead)->get(route('calendar.areas.create'))->assertForbidden();
        $this->actingAs($lead)->get(route('calendar.areas.edit', $area))->assertForbidden();
        $this->actingAs($lead)->post(route('calendar.areas.update', $area), ['name' => 'Nope', 'color' => 'teal'])->assertForbidden();
    }

    public function test_the_area_page_lists_its_weekly_slots_with_links_to_edit_them_and_add_a_regular(): void
    {
        $coordinator = $this->coordinator();
        $area = app(CreateArea::class)->handle($coordinator, 'Front desk');
        $other = app(CreateArea::class)->handle($coordinator, 'Wood shop');
        $slotAttributes = ['weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'headcount' => 1, 'shadow_spots' => 0, 'frequency' => 'weekly', 'valid_from' => '2027-01-07', 'valid_until' => null, 'title' => null, 'is_rotation' => false];
        // Starts beyond the window, so it has no shifts on the calendar yet.
        $later = app(CreateWeeklySlot::class)->handle($coordinator, $area, $slotAttributes);
        $rotation = app(CreateWeeklySlot::class)->handle($coordinator, $area, ['weekday' => 6, 'start_time' => '10:00', 'end_time' => '12:00', 'valid_from' => '2026-10-17', 'is_rotation' => true] + $slotAttributes);
        $elsewhere = app(CreateWeeklySlot::class)->handle($coordinator, $other, ['weekday' => 1] + $slotAttributes);
        $ended = app(CreateWeeklySlot::class)->handle($coordinator, $area, ['weekday' => 2, 'valid_from' => '2026-10-20', 'valid_until' => '2026-10-20'] + $slotAttributes);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 09:00', 'America/Detroit'));

        $this->actingAs($coordinator)->get(route('calendar.areas.edit', $area))
            ->assertOk()
            ->assertSee('Weekly slots')
            ->assertSeeInOrder(['Thursdays, 6:30 to 9:30pm', 'Starts Thursday, January 7', 'Saturdays, 10am to 12pm', 'A rotation'])
            ->assertSee(route('calendar.slots.edit', $later), false)
            ->assertSee(route('calendar.slots.regulars.create', $later), false)
            ->assertSee(route('calendar.slots.edit', $rotation), false)
            ->assertDontSee(route('calendar.slots.regulars.create', $rotation), false)
            ->assertDontSee(route('calendar.slots.edit', $elsewhere), false)
            ->assertDontSee(route('calendar.slots.edit', $ended), false);

        $this->actingAs($coordinator)->get(route('calendar.areas.edit', $other))->assertSee('Mondays, 6:30 to 9:30pm');
        $this->actingAs($coordinator)->get(route('calendar.areas.create'))->assertDontSee('Weekly slots');
    }

    public function test_the_sidebar_links_areas_to_their_edit_page_and_shows_settings_only_to_admins(): void
    {
        $area = app(CreateArea::class)->handle($this->coordinator(), 'Front desk');

        $coordinator = $this->actingAs($this->coordinator())->get(route('calendar.week'));
        $coordinator->assertSee(route('calendar.areas.edit', $area), false)->assertSee('Add an area')->assertDontSee(route('settings'), false);

        $this->actingAs($this->withRole(Role::Admin))->get(route('calendar.week'))->assertSee(route('settings'), false);

        $lead = $this->actingAs($this->withRole(Role::AreaLead, $area))->get(route('calendar.week'));
        $lead->assertDontSee(route('calendar.areas.edit', $area), false)->assertDontSee('Add an area');
    }

    public function test_settings_are_admin_only_and_validated(): void
    {
        $this->actingAs($this->withRole(Role::Coordinator))->get(route('settings'))->assertForbidden();
        $this->actingAs($this->withRole(Role::Coordinator))->put(route('settings'), ['name' => 'X', 'timezone' => 'America/Detroit', 'weeks_ahead' => 4])->assertForbidden();

        $this->actingAs($this->withRole(Role::Admin))->put(route('settings'), ['name' => 'Example Makerspace', 'timezone' => 'Mars/Olympus', 'weeks_ahead' => 0])
            ->assertSessionHasErrors(['timezone', 'weeks_ahead']);
        $this->actingAs($this->withRole(Role::Admin))->put(route('settings'), ['name' => 'Example Makerspace', 'timezone' => 'America/Detroit', 'weeks_ahead' => 27])
            ->assertSessionHasErrors(['weeks_ahead']);
        $this->actingAs($this->withRole(Role::Admin))->put(route('settings'), ['name' => '', 'timezone' => 'America/Detroit', 'weeks_ahead' => 4])
            ->assertSessionHasErrors(['name']);

        $this->actingAs($this->withRole(Role::Admin))->get(route('settings'))->assertOk()->assertSee('Organization settings')->assertSee('America/Detroit');

        $this->actingAs($this->withRole(Role::Admin))->put(route('settings'), ['name' => 'Example Makers', 'timezone' => 'America/Chicago', 'weeks_ahead' => 6])->assertRedirect(route('settings'));
        $organization = Organization::current();
        $this->assertSame(['Example Makers', 'America/Chicago', 6], [$organization->name, $organization->timezone, $organization->weeks_ahead]);
    }

    public function test_the_time_zone_list_is_grouped_with_the_united_states_first(): void
    {
        $html = $this->actingAs($this->withRole(Role::Admin))->get(route('settings'))->assertOk()
            ->assertSee('Shifts already made keep their exact time', false)
            ->assertSee("Lowering it doesn't remove shifts already made.")
            ->getContent();

        $this->assertLessThan(strpos($html, '<optgroup label="Africa">'), strpos($html, '<optgroup label="United States">'));
        $this->assertSame(1, substr_count($html, 'value="America/Detroit"'));
        $this->assertMatchesRegularExpression('/value="America\/Detroit" selected/', $html);
        $this->assertStringContainsString('<optgroup label="Europe">', $html);
    }

    public function test_the_action_refuses_non_admins_and_bad_values(): void
    {
        try {
            app(UpdateSettings::class)->handle($this->coordinator(), 'Example Makerspace', 'America/Detroit', 4);
            $this->fail('A coordinator should not change settings.');
        } catch (AuthorizationException) {
            $this->assertSame(2, Organization::current()->weeks_ahead);
        }

        $this->expectException(ValidationException::class);
        app(UpdateSettings::class)->handle($this->withRole(Role::Admin), 'Example Makerspace', 'Mars/Olympus', 4);
    }

    public function test_raising_weeks_ahead_generates_more_shifts_now(): void
    {
        $area = Area::factory()->create(['name' => 'Front desk']);
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator(), $area, [
            'weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30', 'headcount' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
        $before = Shift::query()->where('weekly_slot_id', $slot->id)->count();
        $this->assertSame(2, $before);

        $admin = $this->withRole(Role::Admin);
        $organization = app(UpdateSettings::class)->handle($admin, 'Example Makerspace', 'America/Detroit', 4);

        $this->assertSame(4, $organization->weeks_ahead);
        $this->assertSame(4, Shift::query()->where('weekly_slot_id', $slot->id)->count());

        $entry = ChangeLogEntry::query()->ofAction('organization.updated')->sole();
        $this->assertSame($admin->id, $entry->actor_id);
        $this->assertSame(['old' => 2, 'new' => 4], $entry->changes['weeks_ahead']);
    }

    public function test_a_new_time_zone_does_not_move_existing_shifts(): void
    {
        $area = Area::factory()->create(['name' => 'Front desk']);
        app(CreateWeeklySlot::class)->handle($this->coordinator(), $area, [
            'weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30', 'headcount' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);
        $before = Shift::query()->orderBy('id')->get()->map(fn (Shift $s) => [$s->id, $s->starts_at->toDateTimeString(), $s->local_date->toDateString()])->all();

        app(UpdateSettings::class)->handle($this->withRole(Role::Admin), 'Example Makerspace', 'America/Los_Angeles', 2);

        $after = Shift::query()->orderBy('id')->get()->map(fn (Shift $s) => [$s->id, $s->starts_at->toDateTimeString(), $s->local_date->toDateString()])->all();
        $this->assertSame($before, $after);
        $this->assertSame('America/Los_Angeles', Organization::current()->timezone);
    }

    public function test_lowering_weeks_ahead_keeps_existing_shifts(): void
    {
        $area = Area::factory()->create(['name' => 'Front desk']);
        app(CreateWeeklySlot::class)->handle($this->coordinator(), $area, [
            'weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30', 'headcount' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'valid_until' => null, 'title' => null, 'is_rotation' => false,
        ]);

        app(UpdateSettings::class)->handle($this->withRole(Role::Admin), 'Example Makerspace', 'America/Detroit', 1);

        $this->assertSame(2, Shift::query()->count());
    }
}
