<?php

namespace Database\Factories;

use App\Enums\AreaColor;
use App\Models\Area;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Area>
 */
class AreaFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => ucfirst(fake()->unique()->words(2, true)),
            'color' => AreaColor::Blue,
        ];
    }
}
