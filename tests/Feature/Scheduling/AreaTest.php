<?php

namespace Tests\Feature\Scheduling;

use App\Models\Area;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaTest extends TestCase
{
    use RefreshDatabase;

    public function test_area_names_are_unique(): void
    {
        Area::create(['name' => 'Front desk']);

        $this->expectException(UniqueConstraintViolationException::class);

        Area::create(['name' => 'Front desk']);
    }
}
