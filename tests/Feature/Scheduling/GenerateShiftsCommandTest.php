<?php

namespace Tests\Feature\Scheduling;

use App\Models\Organization;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GenerateShiftsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_reports_how_many_shifts_it_created(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-15 10:00', 'America/New_York'));
        Organization::factory()->create(['timezone' => 'America/New_York', 'weeks_ahead' => 2]);
        WeeklySlot::factory()->create(['weekday' => 4]);

        $this->artisan('ssvs:generate-shifts')
            ->expectsOutput('Created 2 shifts.')
            ->assertSuccessful();
    }

    public function test_it_fails_clearly_when_no_organization_is_set_up(): void
    {
        $this->artisan('ssvs:generate-shifts')
            ->expectsOutput('No organization is set up yet. Create one before generating shifts.')
            ->assertFailed();
    }

    public function test_it_is_scheduled(): void
    {
        $this->artisan('schedule:list')
            ->expectsOutputToContain('ssvs:generate-shifts')
            ->assertSuccessful();
    }
}
