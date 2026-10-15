<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<\App\Models\Organization>
 */
class OrganizationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => 'Example Makerspace',
            'timezone' => 'America/New_York',
            'weeks_ahead' => 8,
        ];
    }
}
