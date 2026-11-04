<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\GrantRole;
use App\Enums\Role;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class LeadNameTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $this->coordinator = User::factory()->create(['name' => 'Alex']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
    }

    public function test_an_area_can_name_its_leads(): void
    {
        $area = Area::factory()->create(['name' => 'Front desk']);
        $this->actingAs($this->coordinator)->post(route('calendar.areas.update', $area), ['name' => 'Front desk', 'color' => 'blue', 'lead_name' => 'Shift lead'])->assertRedirect();

        $this->assertSame('Shift lead', $area->fresh()->leadTitle());
        $this->assertSame(['old' => null, 'new' => 'Shift lead'], ChangeLogEntry::query()->ofAction('area.updated')->sole()->changes['lead_name']);
    }

    public function test_a_blank_lead_name_is_stored_as_null_and_falls_back_to_area_lead(): void
    {
        $area = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->actingAs($this->coordinator)->post(route('calendar.areas.update', $area), ['name' => 'Front desk', 'color' => $area->color->value, 'lead_name' => '  '])->assertRedirect();

        $this->assertNull($area->fresh()->lead_name);
        $this->assertSame('Area lead', $area->fresh()->leadTitle());
        $this->assertSame(['old' => 'Shift lead', 'new' => null], ChangeLogEntry::query()->ofAction('area.updated')->sole()->changes['lead_name']);
    }

    public function test_a_lead_name_over_40_characters_is_refused(): void
    {
        $area = Area::factory()->create(['name' => 'Front desk']);
        $this->actingAs($this->coordinator)->post(route('calendar.areas.update', $area), ['name' => 'Front desk', 'color' => 'blue', 'lead_name' => str_repeat('a', 41)])
            ->assertSessionHasErrors(['lead_name' => 'Keep it to 40 characters or fewer.']);
    }

    public function test_the_edit_page_shows_the_lead_name_field(): void
    {
        $area = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);

        $this->actingAs($this->coordinator)->get(route('calendar.areas.edit', $area))
            ->assertOk()
            ->assertSee("What do you call this area's leads?")
            ->assertSee('For example Shift lead. Leave it blank for Area lead.')
            ->assertSee('value="Shift lead"', false);
    }

    public function test_role_labels_use_the_area_lead_name(): void
    {
        $front = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $outreach = Area::factory()->create(['name' => 'Outreach']);
        $person = User::factory()->create();
        app(GrantRole::class)->handle(null, $person, Role::AreaLead, $outreach);
        app(GrantRole::class)->handle(null, $person, Role::AreaLead, $front);
        app(GrantRole::class)->handle(null, $person, Role::Coordinator);

        $this->assertSame(['Coordinator', 'Shift lead: Front desk', 'Area lead: Outreach'], $person->roleLabels());

        $admin = User::factory()->create();
        app(GrantRole::class)->handle(null, $admin, Role::Admin);
        app(GrantRole::class)->handle(null, $admin, Role::Coordinator);
        $this->assertSame(['Admin', 'Coordinator'], $admin->roleLabels());
    }

    public function test_the_security_page_shows_the_lead_name_in_roles(): void
    {
        $admin = User::factory()->create();
        app(GrantRole::class)->handle(null, $admin, Role::Admin);
        $this->enableTwoFactor($admin);
        $front = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $person = User::factory()->create();
        app(GrantRole::class)->handle(null, $person, Role::AreaLead, $front);

        $this->actingAs($admin)->get(route('people.security', $person))->assertOk()->assertSee('Shift lead: Front desk');
    }
}
