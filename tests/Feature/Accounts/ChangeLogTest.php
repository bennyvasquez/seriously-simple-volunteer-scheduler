<?php

namespace Tests\Feature\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ChangeLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_records_who_did_what_to_whom(): void
    {
        $actor = User::factory()->create();
        $subject = User::factory()->create();

        $entry = ChangeLogEntry::record($actor, 'user.invited', $subject, ['note' => 'Front desk']);

        $entry = $entry->fresh();
        $this->assertSame($actor->id, $entry->actor_id);
        $this->assertSame('user.invited', $entry->action);
        $this->assertTrue($subject->is($entry->subject));
        $this->assertSame(['note' => 'Front desk'], $entry->details);
        $this->assertNotNull($entry->created_at);
    }

    public function test_a_null_actor_means_the_system(): void
    {
        $entry = ChangeLogEntry::record(null, 'role.granted', User::factory()->create());

        $this->assertNull($entry->fresh()->actor_id);
    }
}
