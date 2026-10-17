<?php

namespace Tests\Feature\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
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

    public function test_it_records_old_and_new_values(): void
    {
        $user = User::factory()->create(['name' => 'Robin']);
        $user->name = 'Robin Q';

        $entry = ChangeLogEntry::record(null, 'user.updated', $user, changes: ChangeLogEntry::changesOf($user));

        $this->assertSame(['name' => ['old' => 'Robin', 'new' => 'Robin Q']], $entry->fresh()->changes);
    }

    public function test_hidden_fields_are_logged_without_values(): void
    {
        $user = User::factory()->create();
        $user->password = 'a brand new password';

        $changes = ChangeLogEntry::changesOf($user);

        $this->assertSame(['password' => ['hidden' => true]], $changes);
    }

    public function test_it_snapshots_the_actor_name_and_who_it_was_about(): void
    {
        $actor = User::factory()->create(['name' => 'Alex']);
        $subject = User::factory()->create();

        $entry = ChangeLogEntry::record($actor, 'user.invited', $subject)->fresh();

        $this->assertSame('Alex', $entry->actor_name);
        $this->assertSame($subject->id, $entry->affected_user_id);
        $this->assertFalse($entry->isBySystem());
    }

    public function test_a_removed_actor_still_reads_as_a_person(): void
    {
        $actor = User::factory()->create(['name' => 'Alex']);
        $entry = ChangeLogEntry::record($actor, 'user.invited', User::factory()->create());

        ChangeLogEntry::forgetPersonalDetailsOf($actor);
        $actor->delete();

        $entry = $entry->fresh();
        $this->assertNull($entry->actor_id);
        $this->assertSame(ChangeLogEntry::REMOVED_PERSON, $entry->actor_name);
        $this->assertFalse($entry->isBySystem());
    }

    public function test_it_filters_by_actor_person_record_action_and_date(): void
    {
        $alex = User::factory()->create();
        $robin = User::factory()->create();
        $sam = User::factory()->create();

        $granted = ChangeLogEntry::record($alex, 'role.granted', $robin);
        $this->travel(2)->days();
        $invited = ChangeLogEntry::record($alex, 'user.invited', $sam);
        $revoked = ChangeLogEntry::record(null, 'role.revoked', $robin);

        $ids = fn ($query) => $query->pluck('id')->sort()->values()->all();

        $this->assertSame([$granted->id, $invited->id], $ids(ChangeLogEntry::query()->byActor($alex)));
        $this->assertSame([$granted->id, $revoked->id], $ids(ChangeLogEntry::query()->about($robin)));
        $this->assertSame([$invited->id], $ids(ChangeLogEntry::query()->forSubject($sam)));
        $this->assertSame([$granted->id, $revoked->id], $ids(ChangeLogEntry::query()->ofAction('role.*')));
        $this->assertSame([$invited->id, $revoked->id], $ids(ChangeLogEntry::query()->between(now()->subDay(), null)));
    }

    public function test_entries_cannot_be_edited_or_deleted(): void
    {
        $entry = ChangeLogEntry::record(null, 'role.granted', User::factory()->create());

        try {
            $entry->update(['action' => 'role.revoked']);
            $this->fail('An entry was edited.');
        } catch (LogicException) {
        }

        $this->expectException(LogicException::class);
        $entry->delete();
    }

    public function test_forgetting_a_person_scrubs_every_copy_and_nothing_else(): void
    {
        $robin = User::factory()->create(['name' => 'Robin', 'email' => 'robin@example.org']);
        $sam = User::factory()->create(['name' => 'Sam', 'email' => 'sam@example.org']);
        $robin->email = 'robin.new@example.org';
        $byRobin = ChangeLogEntry::record($robin, 'user.updated', $robin, changes: ChangeLogEntry::changesOf($robin));
        $sam->email = 'sam.new@example.org';
        $aboutSam = ChangeLogEntry::record($robin, 'user.updated', $sam, changes: ChangeLogEntry::changesOf($sam));

        ChangeLogEntry::forgetPersonalDetailsOf($robin);

        $this->assertSame(ChangeLogEntry::REMOVED_PERSON, $byRobin->fresh()->actor_name);
        $this->assertSame(['old' => null, 'new' => null, 'removed' => true], $byRobin->fresh()->changes['email']);
        $this->assertSame(ChangeLogEntry::REMOVED_PERSON, $aboutSam->fresh()->actor_name);
        $this->assertSame(['old' => 'sam@example.org', 'new' => 'sam.new@example.org'], $aboutSam->fresh()->changes['email']);
    }
}
