<?php

namespace Tests\Feature\Calendar;

use App\Actions\Accounts\GrantRole;
use App\Calendar\PersonSearch;
use App\Enums\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PersonSearchOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_common_short_word_still_finds_the_person_whose_name_starts_with_it(): void
    {
        $coordinator = User::factory()->create();
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator, null);

        // Sixty people whose names contain "da" in the middle sort ahead of "Dana Cruz" by name, so reading the first 50 by name would never reach her.
        foreach (range(1, 60) as $number) {
            User::factory()->create(['name' => 'Ada Example'.$number]);
        }
        User::factory()->create(['name' => 'Dana Cruz']);

        $found = PersonSearch::find($coordinator, 'da', byEmail: true);

        $this->assertSame('Dana Cruz', $found['matches']->first()->display_name);
    }
}
