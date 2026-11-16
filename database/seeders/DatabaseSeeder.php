<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database with the populated example organization the browser tests use, so `php artisan db:seed` gives developers the same install.
     */
    public function run(): void
    {
        $this->call(ExampleOrganizationSeeder::class);
    }
}
