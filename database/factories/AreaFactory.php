<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Area>
 */
class AreaFactory extends Factory
{
    public function definition(): array
    {
        return ['name' => ucfirst(fake()->unique()->words(2, true))];
    }
}
