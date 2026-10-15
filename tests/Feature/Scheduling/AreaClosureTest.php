<?php

namespace Tests\Feature\Scheduling;

use App\Models\Area;
use App\Models\AreaClosure;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class AreaClosureTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_closure_covers_both_its_first_and_last_day(): void
    {
        $closure = AreaClosure::create([
            'area_id' => Area::factory()->create()->id,
            'starts_on' => '2026-12-24',
            'ends_on' => '2026-12-25',
            'reason' => 'Holiday',
        ]);

        $this->assertFalse($closure->covers('2026-12-23'));
        $this->assertTrue($closure->covers('2026-12-24'));
        $this->assertTrue($closure->covers('2026-12-25'));
        $this->assertFalse($closure->covers('2026-12-26'));
    }

    public function test_a_closure_cannot_end_before_it_starts(): void
    {
        $this->expectException(InvalidArgumentException::class);

        AreaClosure::create([
            'area_id' => Area::factory()->create()->id,
            'starts_on' => '2026-12-25',
            'ends_on' => '2026-12-24',
            'reason' => 'Holiday',
        ]);
    }
}
