<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\GenerateShifts;
use App\Calendar\PersonSearch;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Organization;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class LeadVisibilityTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Area $frontDesk;

    private Area $woodshop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['timezone' => 'America/Detroit', 'weeks_ahead' => 4]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->woodshop = Area::factory()->create(['name' => 'Woodshop']);
    }

    private function person(Role $role, ?Area $area = null): User
    {
        $user = User::factory()->create();
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function slot(Area $area): WeeklySlot
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $area->id, 'weekday' => 5, 'start_time' => '15:30', 'end_time' => '18:30', 'people_needed' => 2]);
        app(GenerateShifts::class)->handle(Organization::current());

        return $slot;
    }

    private function names(array $search): array
    {
        return $search['matches']->pluck('name')->all();
    }

    public function test_a_lead_cannot_add_a_regular_starting_in_the_past(): void
    {
        $lead = $this->person(Role::AreaLead, $this->frontDesk);
        $slot = $this->slot($this->frontDesk);
        $robin = User::factory()->create();

        $this->actingAs($lead)->post(route('calendar.slots.regulars.store', $slot), ['user' => $robin->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-13'])
            ->assertSessionHasErrors(['starts_on' => 'The first date can\'t be before today.']);
        $this->assertSame(0, SlotRegular::query()->count());

        try {
            app(AddRegular::class)->handle($lead, $slot, $robin, '2026-10-13');
            $this->fail('The action should refuse a start date before today.');
        } catch (DomainException $e) {
            $this->assertSame('The first date can\'t be before today.', $e->getMessage());
        }

        $this->assertSame(0, SlotRegular::query()->count());
        $this->assertFalse($lead->canSee($robin));
    }

    public function test_a_lead_can_add_a_regular_starting_today(): void
    {
        $lead = $this->person(Role::AreaLead, $this->frontDesk);
        $slot = $this->slot($this->frontDesk);
        $robin = User::factory()->create();

        $this->actingAs($lead)->post(route('calendar.slots.regulars.store', $slot), ['user' => $robin->id, 'frequency' => 'weekly', 'starts_on' => '2026-10-14'])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, SlotRegular::query()->where('user_id', $robin->id)->count());
        $this->assertTrue($lead->canSee($robin));
    }

    public function test_an_impossible_past_regular_doesnt_make_someone_visible(): void
    {
        $lead = $this->person(Role::AreaLead, $this->frontDesk);
        $slot = $this->slot($this->frontDesk);
        $robin = User::factory()->create();
        $other = User::factory()->create();

        // An end before the start never works a date.
        SlotRegular::query()->create(['weekly_slot_id' => $slot->id, 'user_id' => $robin->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-09-01', 'frequency' => 'weekly']);
        SlotRegular::query()->create(['weekly_slot_id' => $slot->id, 'user_id' => $other->id, 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-01', 'frequency' => 'weekly']);

        $this->assertFalse($lead->canSee($robin));
        $this->assertTrue($lead->canSee($other));
    }

    public function test_a_lead_still_sees_people_who_work_their_areas(): void
    {
        $lead = $this->person(Role::AreaLead, $this->frontDesk);
        $regular = User::factory()->create();
        $elsewhere = User::factory()->create();
        app(AddRegular::class)->handle(null, $this->slot($this->frontDesk), $regular, '2026-10-14');
        app(AddRegular::class)->handle(null, $this->slot($this->woodshop), $elsewhere, '2026-10-14');

        $this->assertTrue($lead->canSee($regular));
        $this->assertTrue($lead->canSee($lead));
        $this->assertFalse($lead->canSee($elsewhere));
    }

    public function test_a_lead_cant_learn_a_hidden_surname_from_search(): void
    {
        $lead = $this->person(Role::AreaLead, $this->frontDesk);
        $robin = User::factory()->create(['name' => 'Robin Example']);
        $visible = User::factory()->create(['name' => 'Robin Visible']);
        app(AddRegular::class)->handle(null, $this->slot($this->frontDesk), $visible, '2026-10-14');

        $this->assertSame(['Robin Example'], $this->names(PersonSearch::find($lead, 'Robin E', false, [$visible->id])));
        $this->assertSame(['Robin Example'], $this->names(PersonSearch::find($lead, 'robin e.', false, [$visible->id])));
        $this->assertSame(['Robin Example'], $this->names(PersonSearch::find($lead, 'rob', false, [$visible->id])));
        $this->assertSame([], $this->names(PersonSearch::find($lead, 'Robin Ea', false)));
        $this->assertSame([], $this->names(PersonSearch::find($lead, 'xample', false)));

        // A person the lead can see still matches on any part of their name, and is shown in full.
        $found = PersonSearch::find($lead, 'isibl', false)['matches'];
        $this->assertSame(['Robin Visible'], $found->pluck('display_name')->all());
        $this->assertSame(['Robin E.'], PersonSearch::find($lead, 'Robin E', false, [$visible->id])['matches']->pluck('display_name')->all());
        $this->assertSame($robin->id, PersonSearch::find($lead, 'Robin E', false, [$visible->id])['matches']->sole()->id);
    }

    public function test_a_lead_who_leads_every_area_cant_search_by_email(): void
    {
        $lead = $this->person(Role::AreaLead, $this->frontDesk);
        app(GrantRole::class)->handle(null, $lead, Role::AreaLead, $this->woodshop);
        $robin = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin.secret@example.org']);

        $this->assertFalse(PersonSearch::byEmail($lead));
        $this->assertSame([], $this->names(PersonSearch::find($lead, 'robin.secret', PersonSearch::byEmail($lead))));
    }

    public function test_a_coordinator_still_searches_by_full_name_and_email(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin.secret@example.org']);

        foreach ([Role::Coordinator, Role::Admin] as $role) {
            $viewer = $this->person($role);

            $this->assertTrue(PersonSearch::byEmail($viewer));
            $this->assertSame(['Robin Example'], $this->names(PersonSearch::find($viewer, 'Robin Ex', true)));
            $this->assertSame(['Robin Example'], $this->names(PersonSearch::find($viewer, 'robin.secret', true)));
        }
    }
}
