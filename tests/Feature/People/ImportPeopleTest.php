<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\ImportPeople;
use App\Enums\InvitationStatus;
use App\Enums\Role;
use App\Import\HelloClubExport;
use App\Import\ImportPreview;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class ImportPeopleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Area $frontDesk;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create();
        $this->admin = User::factory()->create(['name' => 'Alex', 'email' => 'admin@example.org']);
        app(GrantRole::class)->handle(null, $this->admin, Role::Admin);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
    }

    private function fixture(): string
    {
        return base_path('tests/Fixtures/hello-club-export.csv');
    }

    private function preview(): ImportPreview
    {
        return app(ImportPeople::class)->preview(HelloClubExport::read($this->fixture()), 'Example Lead Tag');
    }

    public function test_the_reader_handles_the_hello_club_export_shape(): void
    {
        $rows = HelloClubExport::read($this->fixture());

        $this->assertCount(6, $rows);
        $this->assertSame('Avery Example', $rows[0]->name);
        $this->assertSame('shared@example.org', $rows[5]->email);
        $this->assertSame(['Example Volunteer Tag', 'Example Cleared Tag', 'Example Lead Tag'], $rows[2]->tags);

        $preview = app(ImportPeople::class)->preview($rows, 'Example Lead Tag');
        $this->assertCount(3, $preview->add);
        $this->assertCount(3, $preview->problems);
        $this->assertTrue($preview->add->firstWhere('row.name', 'Casey Example')->makeLead);
        $this->assertFalse($preview->add->firstWhere('row.name', 'Avery Example')->makeLead);
        $this->assertEqualsCanonicalizing(['No email address', 'Appears twice in the file, on lines 6 and 7', 'Appears twice in the file, on lines 6 and 7'], $preview->problems->pluck('reason')->all());
    }

    public function test_the_reader_names_a_missing_column(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, "Full name,Profile ID\nAvery Example,abc\n");

        try {
            $this->expectException(InvalidArgumentException::class);
            $this->expectExceptionMessage('The file has no Email column. Is it a Hello Club members export?');
            HelloClubExport::read($path);
        } finally {
            unlink($path);
        }
    }

    public function test_the_reader_keeps_only_name_email_member_id_and_tags(): void
    {
        $row = HelloClubExport::read($this->fixture())[2];

        $this->assertEqualsCanonicalizing(['name', 'email', 'memberId', 'tags', 'line'], array_keys(get_object_vars($row)));
        $this->assertStringNotContainsString('Example Tool', json_encode($row));
    }

    public function test_problems_are_named_plainly(): void
    {
        User::factory()->create(['name' => 'Someone', 'email' => 'avery@example.org', 'member_id' => 'a-different-id']);
        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, "Full name,Email,Profile ID\nBlake Example,not an address,b1\n,nobody@example.org,b2\nAvery Example,avery@example.org,b3\n");

        try {
            $preview = app(ImportPeople::class)->preview(HelloClubExport::read($path), null);
        } finally {
            unlink($path);
        }

        $this->assertSame([
            'The email address isn\'t valid',
            'No name',
            'Matches someone by email, but their membership ID is different',
        ], $preview->problems->pluck('reason')->all());
        $this->assertCount(0, $preview->add);
    }

    public function test_applying_creates_people_without_email_and_makes_shift_leads(): void
    {
        $count = app(ImportPeople::class)->apply($this->admin, $this->preview(), $this->frontDesk);

        $this->assertSame(3, $count);
        $created = User::query()->whereIn('email', ['avery@example.org', 'blake@example.org', 'casey@example.org'])->get();
        $this->assertCount(3, $created);

        foreach ($created as $person) {
            $this->assertSame(InvitationStatus::NotInvited, $person->invitationStatus());
            $this->assertNotNull($person->member_id);
        }

        $casey = $created->firstWhere('email', 'casey@example.org');
        $this->assertTrue($casey->hasRole(Role::AreaLead, $this->frontDesk));
        $this->assertFalse($created->firstWhere('email', 'avery@example.org')->roles()->exists());
        $this->assertSame(3, ChangeLogEntry::query()->where('action', 'user.created')->where('actor_id', $this->admin->id)->count());
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'role.granted')->where('actor_id', $this->admin->id)->count());
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages()->all());
    }

    public function test_nothing_from_the_export_but_name_email_and_member_id_is_stored_or_logged(): void
    {
        app(ImportPeople::class)->apply($this->admin, $this->preview(), $this->frontDesk);

        $stored = json_encode(User::query()->get()->toArray()).json_encode(ChangeLogEntry::query()->get()->toArray());
        foreach (['Jan 1, 2000', '1 Example St', 'Example note', '555-0100', 'Example Tool'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored);
        }
    }

    public function test_existing_people_are_left_alone_except_a_missing_membership_id(): void
    {
        $byMemberId = User::factory()->create(['name' => 'Avery Kept', 'email' => 'avery-old@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f1']);
        $byEmail = User::factory()->create(['name' => 'Blake Kept', 'email' => 'blake@example.org']);
        $off = User::factory()->create(['name' => 'Casey Off', 'email' => 'casey@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f3', 'deactivated_at' => now()]);

        $preview = $this->preview();

        $this->assertCount(0, $preview->add);
        $this->assertCount(3, $preview->existing);
        $this->assertFalse($preview->existing->firstWhere('user.id', $byMemberId->id)->fillMemberId);
        $this->assertTrue($preview->existing->firstWhere('user.id', $byEmail->id)->fillMemberId);

        $count = app(ImportPeople::class)->apply($this->admin, $preview, $this->frontDesk);

        $this->assertSame(0, $count);
        $this->assertSame('Avery Kept', $byMemberId->fresh()->name);
        $this->assertSame('avery-old@example.org', $byMemberId->fresh()->email);
        $this->assertSame('a1b2c3d4e5f6a1b2c3d4e5f2', $byEmail->fresh()->member_id);
        $this->assertNotNull($off->fresh()->deactivated_at);
        $this->assertFalse($off->fresh()->hasRole(Role::AreaLead));

        $entry = ChangeLogEntry::query()->where('action', 'user.member_id_added')->sole();
        $this->assertSame($byEmail->id, $entry->subject_id);
        $this->assertSame(['old' => null, 'new' => 'a1b2c3d4e5f6a1b2c3d4e5f2'], $entry->changes['member_id']);
    }

    public function test_the_removed_person_is_never_matched(): void
    {
        User::factory()->create(['name' => 'Removed person', 'email' => 'removed@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f1', 'is_removed_person' => true]);

        $preview = $this->preview();

        $this->assertCount(0, $preview->existing);
        $this->assertTrue($preview->add->contains('row.name', 'Avery Example'));
    }

    public function test_only_admins_can_import(): void
    {
        $coordinator = User::factory()->create(['email' => 'coordinator@example.org']);
        app(GrantRole::class)->handle(null, $coordinator, Role::Coordinator);
        $preview = $this->preview();

        try {
            app(ImportPeople::class)->apply($coordinator, $preview, $this->frontDesk);
            $this->fail('A coordinator imported people.');
        } catch (AuthorizationException) {
            // Expected.
        }

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'coordinator@example.org'])->assertFailed();
        $this->assertSame(2, User::query()->count());
    }

    public function test_a_failed_create_keeps_nothing(): void
    {
        $preview = $this->preview();
        // Someone takes Casey's email after the preview, so the third create fails.
        User::factory()->create(['email' => 'casey@example.org']);

        try {
            app(ImportPeople::class)->apply($this->admin, $preview, $this->frontDesk);
            $this->fail('Expected the import to fail.');
        } catch (\DomainException) {
            // Expected.
        }

        $this->assertFalse(User::query()->where('email', 'avery@example.org')->exists());
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'user.created')->where('actor_id', $this->admin->id)->count());
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'role.granted')->where('actor_id', $this->admin->id)->count());
    }

    public function test_an_unexpected_database_error_prints_no_contact_details(): void
    {
        $this->app->bind(ImportPeople::class, fn () => new class extends ImportPeople
        {
            public function apply(User $admin, ImportPreview $preview, ?Area $leadArea): int
            {
                throw new QueryException('mysql', 'insert into users (email) values (?)', ['secret@example.org'], new \Exception('Duplicate entry secret@example.org'));
            }
        });

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org'])
            ->expectsConfirmation('Add these 3 people? Nobody will be emailed.', 'yes')
            ->expectsOutputToContain('Something went wrong, so nothing was saved.')
            ->doesntExpectOutputToContain('secret@example.org')
            ->assertFailed();
    }

    public function test_a_membership_id_taken_during_the_prompt_is_refused_with_the_line(): void
    {
        $preview = $this->preview();
        User::factory()->create(['member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f2']);

        try {
            app(ImportPeople::class)->apply($this->admin, $preview, $this->frontDesk);
            $this->fail('Expected the import to fail.');
        } catch (\DomainException $exception) {
            $this->assertSame('Line 3: Someone already has that membership ID. Nothing was saved.', $exception->getMessage());
        }

        $this->assertFalse(User::query()->where('email', 'avery@example.org')->exists());
    }

    public function test_a_duplicate_membership_id_in_the_file_is_a_problem_on_both_rows(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, "Full name,Email,Profile ID\nAvery Example,avery@example.org,same\nBlake Example,blake@example.org,same\n");

        try {
            $preview = app(ImportPeople::class)->preview(HelloClubExport::read($path), null);
        } finally {
            unlink($path);
        }

        $this->assertCount(0, $preview->add);
        $this->assertSame(['Appears twice in the file, on lines 2 and 3', 'Appears twice in the file, on lines 2 and 3'], $preview->problems->pluck('reason')->all());
    }

    public function test_names_and_membership_ids_that_are_too_long_are_problems(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, 'Full name,Email,Profile ID'."\n".str_repeat('a', 256).",a@example.org,x\nBlake Example,b@example.org,".str_repeat('b', 65)."\n");

        try {
            $preview = app(ImportPeople::class)->preview(HelloClubExport::read($path), null);
        } finally {
            unlink($path);
        }

        $this->assertSame(['The name is too long', 'The membership ID is too long'], $preview->problems->pluck('reason')->all());
    }

    public function test_the_prompt_names_membership_id_fill_ins_when_nobody_is_added(): void
    {
        User::factory()->create(['email' => 'avery@example.org']);
        User::factory()->create(['email' => 'blake@example.org']);
        User::factory()->create(['email' => 'casey@example.org']);

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org'])
            ->expectsConfirmation('Add membership IDs to 3 people? Nobody will be emailed.', 'no')
            ->assertSuccessful();
    }

    public function test_the_command_previews_then_imports_on_yes(): void
    {
        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--lead-tag' => 'Example Lead Tag', '--lead-area' => 'Front desk'])
            ->expectsOutputToContain('avery@example.org')
            ->doesntExpectOutputToContain('1 Example St')
            ->expectsConfirmation('Add these 3 people? Nobody will be emailed.', 'yes')
            ->assertSuccessful();

        $this->assertSame(3, User::query()->where('is_removed_person', false)->where('id', '!=', $this->admin->id)->whereNull('invited_at')->count());
    }

    public function test_the_command_does_nothing_on_no_or_without_interaction(): void
    {
        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org'])
            ->expectsConfirmation('Add these 3 people? Nobody will be emailed.', 'no')
            ->assertSuccessful();
        $this->assertSame(1, User::query()->count());

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--no-interaction' => true])
            ->expectsOutputToContain('casey@example.org')
            ->assertSuccessful();
        $this->assertSame(1, User::query()->count());
    }

    public function test_the_command_refuses_an_unknown_lead_area_before_reading_the_file(): void
    {
        $this->frontDesk->delete();

        $this->artisan('ssvs:import-people', ['file' => '/no/such/file.csv', '--as' => 'admin@example.org', '--lead-tag' => 'Example Lead Tag', '--lead-area' => 'Front desk'])
            ->expectsOutputToContain('There\'s no area called Front desk. Add it on the calendar first.')
            ->assertFailed();

        $this->assertSame(1, User::query()->count());
    }

    public function test_the_lead_options_come_together(): void
    {
        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--lead-tag' => 'Example Lead Tag'])->assertFailed();
    }

    public function test_the_cleared_tag_clears_new_people(): void
    {
        $preview = app(ImportPeople::class)->preview(HelloClubExport::read($this->fixture()), null, 'Example Cleared Tag');

        $this->assertTrue($preview->add->firstWhere('row.name', 'Casey Example')->clear);
        $this->assertFalse($preview->add->firstWhere('row.name', 'Avery Example')->clear);

        app(ImportPeople::class)->apply($this->admin, $preview, null);

        $this->assertTrue(User::query()->where('email', 'casey@example.org')->sole()->isCleared());
        $this->assertFalse(User::query()->where('email', 'avery@example.org')->sole()->isCleared());
        $entry = ChangeLogEntry::query()->where('action', 'user.cleared')->where('details->via', 'import')->sole();
        $this->assertSame('import', $entry->details['via']);
        $this->assertSame($this->admin->id, $entry->actor_id);
        $this->assertCount(0, app('mailer')->getSymfonyTransport()->messages()->all());
    }

    public function test_the_cleared_tag_clears_people_already_imported(): void
    {
        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org'])
            ->expectsConfirmation('Add these 3 people? Nobody will be emailed.', 'yes')
            ->assertSuccessful();
        $blake = User::query()->where('email', 'blake@example.org')->sole();
        $before = $blake->fresh()->only(['name', 'email', 'member_id', 'invited_at']);

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--cleared-tag' => 'Example Volunteer Tag'])
            ->expectsOutputToContain('2 people already here will be cleared.')
            ->expectsConfirmation('Clear 2 people for regular shifts? Nobody will be emailed.', 'yes')
            ->assertSuccessful();

        $this->assertTrue($blake->fresh()->isCleared());
        $this->assertTrue(User::query()->where('email', 'casey@example.org')->sole()->isCleared());
        $this->assertFalse(User::query()->where('email', 'avery@example.org')->sole()->isCleared());
        $this->assertSame($before, $blake->fresh()->only(['name', 'email', 'member_id', 'invited_at']));
        $this->assertSame(2, ChangeLogEntry::query()->where('action', 'user.cleared')->where('details->via', 'import')->count());
    }

    public function test_people_already_cleared_arent_logged_again(): void
    {
        User::factory()->cleared()->create(['name' => 'Blake Kept', 'email' => 'blake@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f2']);
        User::factory()->create(['name' => 'Casey Kept', 'email' => 'casey@example.org']);

        $preview = app(ImportPeople::class)->preview(HelloClubExport::read($this->fixture()), null, 'Example Volunteer Tag');

        $this->assertCount(1, $preview->clearExisting);
        app(ImportPeople::class)->apply($this->admin, $preview, null);
        app(ImportPeople::class)->apply($this->admin, app(ImportPeople::class)->preview(HelloClubExport::read($this->fixture()), null, 'Example Volunteer Tag'), null);

        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'user.cleared')->where('details->via', 'import')->count());
    }

    public function test_tags_are_matched_the_same_way_as_the_lead_tag(): void
    {
        $rows = HelloClubExport::read($this->fixture());
        $lead = app(ImportPeople::class)->preview($rows, 'Example Cleared Tag');
        $cleared = app(ImportPeople::class)->preview($rows, null, 'Example Cleared Tag');
        $wrongCase = app(ImportPeople::class)->preview($rows, 'example cleared tag', 'example cleared tag');

        $this->assertSame($lead->add->where('makeLead', true)->pluck('row.name')->all(), $cleared->add->where('clear', true)->pluck('row.name')->all());
        $this->assertCount(0, $wrongCase->add->where('makeLead', true));
        $this->assertCount(0, $wrongCase->add->where('clear', true));
    }

    public function test_the_final_message_says_what_was_cleared_on_a_re_import(): void
    {
        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org'])
            ->expectsConfirmation('Add these 3 people? Nobody will be emailed.', 'yes')
            ->assertSuccessful();

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--cleared-tag' => 'Example Volunteer Tag'])
            ->expectsConfirmation('Clear 2 people for regular shifts? Nobody will be emailed.', 'yes')
            ->expectsOutputToContain('Cleared 2 people for regular shifts. Nobody was emailed.')
            ->doesntExpectOutputToContain('Added 0')
            ->assertSuccessful();
    }

    public function test_the_final_message_says_what_was_added_and_cleared(): void
    {
        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--cleared-tag' => 'Example Volunteer Tag'])
            ->expectsConfirmation('Add these 3 people? Nobody will be emailed.', 'yes')
            ->expectsOutputToContain('Added 3 people as not invited yet and cleared 2 people for regular shifts. Nobody was emailed.')
            ->assertSuccessful();
    }

    public function test_the_cleared_tag_leaves_people_moved_back_to_shadowing_alone(): void
    {
        $blake = User::factory()->create(['name' => 'Blake Example', 'email' => 'blake@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f2', 'shadowing_since' => now()]);

        $preview = app(ImportPeople::class)->preview(HelloClubExport::read($this->fixture()), null, 'Example Volunteer Tag');

        $this->assertCount(0, $preview->clearExisting);
        $this->assertSame(['Blake Example'], $preview->movedBack->pluck('row.name')->all());
        $this->assertCount(0, $preview->leftAlone());

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--cleared-tag' => 'Example Volunteer Tag', '--no-interaction' => true])
            ->expectsOutputToContain('Moved back to shadowing, left alone (1)')
            ->expectsOutputToContain('Already here, left alone (0)')
            ->doesntExpectOutputToContain('already here will be cleared')
            ->assertSuccessful();

        app(ImportPeople::class)->apply($this->admin, $preview, null);

        $this->assertFalse($blake->fresh()->isCleared());
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'user.cleared')->where('subject_id', $blake->id)->count());
    }

    public function test_the_cleared_tag_leaves_people_who_are_turned_off_alone(): void
    {
        $blake = User::factory()->deactivated()->create(['name' => 'Blake Example', 'email' => 'blake@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f2']);

        $preview = app(ImportPeople::class)->preview(HelloClubExport::read($this->fixture()), null, 'Example Volunteer Tag');

        $this->assertCount(0, $preview->clearExisting);
        $this->assertSame(['Blake Example'], $preview->leftAlone()->pluck('row.name')->all());
        app(ImportPeople::class)->apply($this->admin, $preview, null);

        $this->assertFalse($blake->fresh()->isCleared());
    }

    public function test_people_being_cleared_arent_listed_as_left_alone(): void
    {
        User::factory()->create(['name' => 'Avery Example', 'email' => 'avery@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f1']);
        User::factory()->create(['name' => 'Blake Example', 'email' => 'blake@example.org', 'member_id' => 'a1b2c3d4e5f6a1b2c3d4e5f2']);

        $preview = app(ImportPeople::class)->preview(HelloClubExport::read($this->fixture()), null, 'Example Volunteer Tag');

        $this->assertSame(['Avery Example'], $preview->leftAlone()->pluck('row.name')->all());

        $this->artisan('ssvs:import-people', ['file' => $this->fixture(), '--as' => 'admin@example.org', '--cleared-tag' => 'Example Volunteer Tag', '--no-interaction' => true])
            ->expectsOutputToContain('Already here, left alone (1)')
            ->expectsOutputToContain('1 person already here will be cleared.')
            ->assertSuccessful();
    }
}
