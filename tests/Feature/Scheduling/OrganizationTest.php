<?php

namespace Tests\Feature\Scheduling;

use App\Models\Organization;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use LogicException;
use RuntimeException;
use Tests\TestCase;

class OrganizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_app_runs_in_utc(): void
    {
        $this->assertSame('UTC', config('app.timezone'));
    }

    public function test_current_returns_the_one_organization(): void
    {
        $organization = Organization::factory()->create();

        $this->assertTrue($organization->is(Organization::current()));
    }

    public function test_current_explains_what_is_missing_when_none_exists(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No organization is set up yet');

        Organization::current();
    }

    public function test_only_one_organization_can_exist(): void
    {
        Organization::factory()->create();

        $this->expectException(LogicException::class);

        Organization::factory()->create();
    }

    public function test_the_time_zone_must_be_a_real_one(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Organization::factory()->create(['timezone' => 'Mars/Olympus_Mons']);
    }

    public function test_weeks_ahead_defaults_to_eight(): void
    {
        $organization = Organization::create(['name' => 'Example Makerspace', 'timezone' => 'America/New_York']);

        $this->assertSame(8, $organization->weeks_ahead);
    }

    public function test_today_uses_the_organization_time_zone(): void
    {
        // 03:00 UTC on the 15th is still 23:00 on the 14th in New York.
        $this->travelTo(CarbonImmutable::parse('2026-10-15 03:00', 'UTC'));
        $organization = Organization::factory()->create(['timezone' => 'America/New_York']);

        $this->assertSame('2026-10-14', $organization->today()->toDateString());
    }
}
