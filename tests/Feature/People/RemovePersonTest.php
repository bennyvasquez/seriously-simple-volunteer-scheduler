<?php

namespace Tests\Feature\People;

use App\Actions\Accounts\CreatePerson;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\ImportPeople;
use App\Actions\Accounts\InvitePerson;
use App\Actions\Accounts\RemovePerson;
use App\Actions\Accounts\RequestEmailChange;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\UpdateWeeklySlot;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Import\HelloClubExport;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\LoginLink;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use App\Notifications\InvitationNotification;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class RemovePersonTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private Area $frontDesk;

    private User $admin;

    private User $coordinator;

    private User $lead;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->admin = $this->withRole(Role::Admin, null, 'Morgan Admin', 'morgan@example.org');
        $this->coordinator = $this->withRole(Role::Coordinator, null, 'Alex Coordinator', 'alex@example.org');
        $this->lead = $this->withRole(Role::AreaLead, $this->frontDesk, 'Casey Lead', 'casey@example.org');
    }

    private function withRole(Role $role, ?Area $area, string $name, string $email): User
    {
        $user = User::factory()->create(['name' => $name, 'email' => $email]);
        app(GrantRole::class)->handle(null, $user, $role, $area);
        $this->enableTwoFactor($user);

        return $user;
    }

    private function shift(string $localDate, bool $cancelled = false): Shift
    {
        return Shift::query()->create([
            'area_id' => $this->frontDesk->id, 'local_date' => $localDate,
            'starts_at' => CarbonImmutable::parse($localDate.' 18:00', 'America/Detroit')->utc(), 'ends_at' => CarbonImmutable::parse($localDate.' 21:00', 'America/Detroit')->utc(),
            'headcount' => 2, 'shadow_spots' => 0, 'cancelled_at' => $cancelled ? now() : null,
        ]);
    }

    /** A weekly Thursday slot in Front desk from October 15, in the keys CreateWeeklySlot and UpdateWeeklySlot take. */
    private function slotAttributes(array $overrides): array
    {
        return $overrides + [
            'weekday' => 4, 'start_time' => '15:30', 'end_time' => '18:30', 'headcount' => 1, 'shadow_spots' => 0,
            'frequency' => 'weekly', 'valid_from' => '2026-10-15', 'title' => null, 'is_rotation' => false,
        ];
    }

    private function regular(User $person, ?string $endsOn): SlotRegular
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id]);

        return SlotRegular::create(['weekly_slot_id' => $slot->id, 'user_id' => $person->id, 'starts_on' => '2026-09-03', 'ends_on' => $endsOn]);
    }

    public function test_removing_someone_leaves_no_trace_of_them(): void
    {
        $robin = app(CreatePerson::class)->handle($this->coordinator, 'Robin Example', 'robin@example.org', 'a1b2c3d4e5f6a1b2c3d4e5f6');
        $robin->forceFill(['invited_at' => now(), 'email_verified_at' => now()])->save();
        app(GrantRole::class)->handle($this->coordinator, $robin, Role::AreaLead, $this->frontDesk);

        $worked = $this->shift('2026-10-20');
        app(PutOnShift::class)->handle($this->coordinator, $worked, $robin, AssignmentType::Regular);
        $cancelled = $this->shift('2026-10-21');
        app(PutOnShift::class)->handle($this->coordinator, $cancelled, $robin, AssignmentType::Regular);
        app(CancelShift::class)->handle($this->coordinator, $cancelled, 'Snow day');
        // A slot change cancels Thursday, October 22; Robin becomes its regular for that one date; changing the slot back brings the date back with Robin on it (logged as added_regular_ids).
        $slot = app(CreateWeeklySlot::class)->handle($this->coordinator, $this->frontDesk, $this->slotAttributes(['valid_until' => null]));
        app(UpdateWeeklySlot::class)->handle($this->coordinator, $slot, $this->slotAttributes(['valid_until' => '2026-10-15']));
        $regular = SlotRegular::create(['weekly_slot_id' => $slot->id, 'user_id' => $robin->id, 'starts_on' => '2026-10-22', 'ends_on' => '2026-10-22']);
        ChangeLogEntry::record($this->coordinator, 'regular.added', $regular, changes: ['user_id' => ['old' => null, 'new' => $robin->id]], about: $robin);
        app(UpdateWeeklySlot::class)->handle($this->coordinator, $slot->fresh(), $this->slotAttributes(['valid_until' => null]));
        $revived = $slot->shifts()->where('local_date', '2026-10-22')->sole();
        $this->assertSame([$robin->id], ChangeLogEntry::query()->forSubject($revived)->ofAction('shift.updated')->sole()->details['added_regular_ids']);
        ChangeLogEntry::record($robin, 'shift.updated', $worked, ['area_id' => $this->frontDesk->id]);
        ChangeLogEntry::record($robin, 'user.password_changed', $robin, changes: ['password' => ['hidden' => true]]);

        DB::table('sessions')->insert(['id' => 'robin-session', 'user_id' => $robin->id, 'payload' => '', 'last_activity' => now()->timestamp]);
        DB::table('password_reset_tokens')->insert(['email' => 'robin@example.org', 'token' => 'hashed', 'created_at' => now()]);

        $this->travelTo(CarbonImmutable::parse('2026-10-25 09:00', 'America/Detroit'));

        $this->actingAs($this->coordinator)->post(route('people.remove.store', $robin), ['confirm' => '  robin '])
            ->assertRedirect(route('people.index'))
            ->assertSessionHas('status', 'Robin was removed.');

        $removed = User::removedPerson();

        $this->assertDatabaseMissing('users', ['id' => $robin->id]);
        $this->assertDatabaseMissing('users', ['email' => 'robin@example.org']);
        $this->assertDatabaseMissing('sessions', ['user_id' => $robin->id]);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'robin@example.org']);
        $this->assertDatabaseMissing('role_assignments', ['user_id' => $robin->id]);
        $this->assertSame(0, Assignment::query()->where('user_id', $robin->id)->count());
        $this->assertSame($removed->id, $worked->assignments()->sole()->user_id);
        $this->assertSame($removed->id, $cancelled->assignments()->sole()->user_id);
        $this->assertSame($removed->id, $regular->fresh()->user_id);

        // Nothing in the change log points at Robin's id or says who Robin was.
        $this->assertSame(0, ChangeLogEntry::query()->where('actor_id', $robin->id)->orWhere('affected_user_id', $robin->id)->count());
        $this->assertSame(0, ChangeLogEntry::query()->where('subject_type', $robin->getMorphClass())->where('subject_id', $robin->id)->count());

        foreach (ChangeLogEntry::query()->get() as $entry) {
            $row = json_encode($entry->getAttributes());
            $this->assertStringNotContainsStringIgnoringCase('robin', $row);
            $this->assertStringNotContainsString('a1b2c3d4e5f6a1b2c3d4e5f6', $row);

            foreach (['old', 'new'] as $side) {
                $this->assertNotSame($robin->id, $entry->changes['user_id'][$side] ?? null);
            }

            $this->assertNotContains($robin->id, $entry->details['people'] ?? []);
            $this->assertNotContains($robin->id, $entry->details['added_regular_ids'] ?? []);
        }

        $made = ChangeLogEntry::query()->where('actor_id', $removed->id)->get();
        $this->assertCount(2, $made);
        $this->assertTrue($made->every(fn ($entry) => $entry->actor_id === $removed->id && $entry->actor_name === ChangeLogEntry::REMOVED_PERSON));
        $this->assertSame($removed->id, ChangeLogEntry::query()->ofAction('user.password_changed')->sole()->affected_user_id);
        $this->assertSame($removed->id, ChangeLogEntry::query()->ofAction('regular.added')->sole()->changes['user_id']['new']);
        $this->assertSame([$removed->id], ChangeLogEntry::query()->forSubject($cancelled)->ofAction('shift.cancelled')->sole()->details['people']);
        $this->assertSame([$removed->id], ChangeLogEntry::query()->forSubject($revived)->ofAction('shift.updated')->sole()->details['added_regular_ids']);
        $this->assertSame($removed->id, $revived->assignments()->sole()->user_id);

        $entry = ChangeLogEntry::query()->ofAction('user.removed')->sole();
        $this->assertSame($this->coordinator->id, $entry->actor_id);
        $this->assertTrue($entry->subject->is($removed));
        $this->assertSame($removed->id, $entry->affected_user_id);
        $this->assertNull($entry->details);
        $this->assertNull($entry->changes);

        $this->actingAs($this->coordinator)->get(route('people.index'))->assertOk()->assertSee('Robin was removed.');
        $this->actingAs($this->coordinator)->get(route('people.index', ['show' => 'all']))
            ->assertOk()
            ->assertDontSee(ChangeLogEntry::REMOVED_PERSON)
            ->assertDontSee('Robin');
    }

    public function test_the_shared_record_is_made_once_and_logged_by_the_system(): void
    {
        $removed = User::removedPerson();

        $this->assertTrue($removed->is(User::removedPerson()));
        $this->assertSame(1, User::query()->where('is_removed_person', true)->count());
        $this->assertSame(ChangeLogEntry::REMOVED_PERSON, $removed->name);
        $this->assertSame('removed-person@invalid', $removed->email);
        $this->assertNull($removed->password);
        $this->assertNull($removed->invited_at);
        $this->assertNull($removed->member_id);
        $this->assertNotNull($removed->deactivated_at);

        $entry = ChangeLogEntry::query()->ofAction('user.created')->forSubject($removed)->sole();
        $this->assertTrue($entry->isBySystem());
    }

    public function test_two_removed_people_on_the_same_past_shift(): void
    {
        $shift = $this->shift('2026-10-07');
        [$robin, $casey] = User::factory()->count(2)->create();
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $robin->id, 'type' => AssignmentType::Regular]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $casey->id, 'type' => AssignmentType::Regular]);

        app(RemovePerson::class)->handle($this->coordinator, $robin);
        app(RemovePerson::class)->handle($this->coordinator, $casey);

        $this->assertSame(2, $shift->assignments()->where('user_id', User::removedPerson()->id)->count());
        $this->assertSame(2, ChangeLogEntry::query()->ofAction('user.removed')->count());
        $this->assertSame(1, User::query()->where('is_removed_person', true)->count());
    }

    public function test_removal_waits_for_future_shifts_and_regular_slots(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example']);

        foreach (['2026-10-20', '2026-10-21', '2026-10-22'] as $date) {
            Assignment::create(['shift_id' => $this->shift($date)->id, 'user_id' => $robin->id, 'type' => AssignmentType::Regular]);
        }

        try {
            app(RemovePerson::class)->handle($this->coordinator, $robin);
            $this->fail('Removal should wait for future shifts.');
        } catch (DomainException $e) {
            $this->assertSame('Robin is still on 3 future shifts. Take them off those first.', $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $robin->id]);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.removed')->count());
        $this->assertSame(0, User::query()->where('is_removed_person', true)->count());

        // On screen, the refusal shows on the confirmation page, before and after pressing the button.
        $this->actingAs($this->coordinator)->get(route('people.remove', $robin))
            ->assertOk()
            ->assertSee('Robin is still on 3 future shifts. Take them off those first.')
            ->assertSee('Back to Robin\'s page', false)
            ->assertSee(route('people.show', $robin))
            ->assertDontSee('Remove Robin for good')
            ->assertDontSee('name="confirm"', false);
        $this->actingAs($this->coordinator)->from(route('people.remove', $robin))->post(route('people.remove.store', $robin), ['confirm' => 'Robin'])
            ->assertRedirect(route('people.remove', $robin))
            ->assertSessionHasErrors(['remove' => 'Robin is still on 3 future shifts. Take them off those first.']);
        $this->assertDatabaseHas('users', ['id' => $robin->id]);

        $sam = User::factory()->create(['name' => 'Sam Example']);
        Assignment::create(['shift_id' => $this->shift('2026-10-23')->id, 'user_id' => $sam->id, 'type' => AssignmentType::Regular]);
        $this->expectRefusal($sam, 'Sam is still on 1 future shift. Take them off that first.');

        // A cancelled future shift and a regular slot that ended yesterday don't block.
        $casey = User::factory()->create(['name' => 'Casey Example']);
        Assignment::create(['shift_id' => $this->shift('2026-10-24', cancelled: true)->id, 'user_id' => $casey->id, 'type' => AssignmentType::Regular]);
        $this->regular($casey, '2026-10-13');
        app(RemovePerson::class)->handle($this->coordinator, $casey);
        $this->assertDatabaseMissing('users', ['id' => $casey->id]);

        // A regular slot with no end date, or one ending today, blocks.
        $jordan = User::factory()->create(['name' => 'Jordan Example']);
        $this->regular($jordan, null);
        $this->expectRefusal($jordan, 'Jordan is still a regular. End that first.');

        $taylor = User::factory()->create(['name' => 'Taylor Example']);
        $this->regular($taylor, '2026-10-14');
        $this->expectRefusal($taylor, 'Taylor is still a regular. End that first.');
    }

    public function test_a_regular_on_a_slot_that_has_ended_does_not_block_removal(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example']);
        $ended = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'valid_until' => '2026-10-13']);
        SlotRegular::create(['weekly_slot_id' => $ended->id, 'user_id' => $robin->id, 'starts_on' => '2026-09-03', 'ends_on' => null]);
        app(RemovePerson::class)->handle($this->coordinator, $robin);
        $this->assertDatabaseMissing('users', ['id' => $robin->id]);

        $sam = User::factory()->create(['name' => 'Sam Example']);
        $ending = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'valid_until' => '2026-10-14']);
        SlotRegular::create(['weekly_slot_id' => $ending->id, 'user_id' => $sam->id, 'starts_on' => '2026-09-03', 'ends_on' => null]);
        $this->expectRefusal($sam, 'Sam is still a regular. End that first.');
    }

    public function test_the_removed_persons_security_page_is_not_found(): void
    {
        $removed = User::removedPerson();

        $this->actingAs($this->admin)->get(route('people.security', $removed))->assertNotFound();
        $this->actingAs($this->admin)->post(route('people.two-factor.reset', $removed), ['identity_checked' => '1'])->assertNotFound();
    }

    private function expectRefusal(User $person, string $message): void
    {
        try {
            app(RemovePerson::class)->handle($this->coordinator, $person);
            $this->fail('Removal should have been refused.');
        } catch (DomainException $e) {
            $this->assertSame($message, $e->getMessage());
        }

        $this->assertDatabaseHas('users', ['id' => $person->id]);
    }

    public function test_the_confirmation_needs_the_first_name(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example']);

        $this->actingAs($this->coordinator)->get(route('people.show', $robin))
            ->assertOk()
            ->assertSee('Remove Robin')
            ->assertSee('Removing someone deletes their name, email address and membership ID from SSVS. Shifts they worked stay counted, under Removed person, and so do their entries in the change log. This can\'t be undone.', false);

        $this->actingAs($this->coordinator)->get(route('people.remove', $robin))
            ->assertOk()
            ->assertSee('Removing someone deletes their name, email address and membership ID from SSVS.')
            ->assertSee('Remove Robin for good');

        $this->actingAs($this->coordinator)->from(route('people.remove', $robin))->post(route('people.remove.store', $robin), ['confirm' => 'Robyn'])
            ->assertRedirect(route('people.remove', $robin))
            ->assertSessionHasErrors(['confirm' => 'Type Robin to confirm.']);
        $this->actingAs($this->coordinator)->post(route('people.remove.store', $robin), [])->assertSessionHasErrors(['confirm' => 'Type Robin to confirm.']);

        $this->assertDatabaseHas('users', ['id' => $robin->id]);
        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.removed')->count());
    }

    public function test_who_can_remove_whom(): void
    {
        $robin = User::factory()->create(['name' => 'Robin Example']);
        Assignment::create(['shift_id' => $this->shift('2026-10-07')->id, 'user_id' => $robin->id, 'type' => AssignmentType::Regular]);

        // A coordinator can't remove an admin, and nobody can remove themselves.
        $this->actingAs($this->coordinator)->get(route('people.remove', $this->admin))->assertForbidden();
        $this->actingAs($this->coordinator)->post(route('people.remove.store', $this->admin), ['confirm' => 'Morgan'])->assertForbidden();
        $this->actingAs($this->admin)->post(route('people.remove.store', $this->admin), ['confirm' => 'Morgan'])->assertForbidden();
        $this->actingAs($this->coordinator)->get(route('people.show', $this->admin))->assertDontSee('Remove Morgan');

        // A lead who can see Robin still can't remove them.
        $this->actingAs($this->lead)->get(route('people.show', $robin))->assertOk()->assertDontSee('Remove Robin');
        $this->actingAs($this->lead)->get(route('people.remove', $robin))->assertForbidden();
        $this->actingAs($this->lead)->post(route('people.remove.store', $robin), ['confirm' => 'Robin'])->assertForbidden();

        // The removed person isn't anyone's to find.
        $removed = User::removedPerson();
        $this->actingAs($this->admin)->get(route('people.remove', $removed))->assertNotFound();
        $this->actingAs($this->admin)->post(route('people.remove.store', $removed), ['confirm' => 'Removed'])->assertNotFound();

        foreach ([[$this->coordinator, $this->admin], [$this->admin, $this->admin], [$this->lead, $robin], [$this->admin, $removed]] as [$actor, $person]) {
            try {
                app(RemovePerson::class)->handle($actor, $person);
                $this->fail('Removal should have been refused.');
            } catch (AuthorizationException) {
                $this->assertDatabaseHas('users', ['id' => $person->id]);
            }
        }

        $this->assertSame(0, ChangeLogEntry::query()->ofAction('user.removed')->count());

        // An admin can remove another admin.
        $other = $this->withRole(Role::Admin, null, 'Riley Admin', 'riley@example.org');
        $this->actingAs($this->admin)->post(route('people.remove.store', $other), ['confirm' => 'Riley'])->assertRedirect(route('people.index'));
        $this->assertDatabaseMissing('users', ['id' => $other->id]);
    }

    public function test_no_account_can_take_an_address_at_the_invalid_domain(): void
    {
        $this->actingAs($this->coordinator)->from(route('people.invite'))
            ->post(route('people.invite.store'), ['name' => 'Robin Example', 'email' => 'Removed-Person@INVALID', 'starts_as' => 'volunteer'])
            ->assertRedirect(route('people.invite'))
            ->assertSessionHasErrors(['email' => 'That email address can\'t be used.']);

        $sam = User::factory()->create(['email' => 'sam@example.org']);
        $this->actingAs($sam)->withSession(['auth.password_confirmed_at' => time()])->from(route('account.email.edit'))
            ->post(route('account.email.update'), ['email' => 'removed-person@invalid'])
            ->assertSessionHasErrors(['email' => 'That email address can\'t be used.']);
        app(RequestEmailChange::class)->handle($sam, 'someone@invalid');
        $this->assertSame(0, LoginLink::query()->count());

        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, "Full name,Email,Profile ID\nBlake Example,removed-person@invalid,b1\n");

        try {
            $preview = app(ImportPeople::class)->preview(HelloClubExport::read($path), null);
        } finally {
            unlink($path);
        }

        $this->assertSame(['That email address can\'t be used'], $preview->problems->pluck('reason')->all());

        try {
            app(CreatePerson::class)->handle(null, 'Blake Example', 'removed-person@invalid');
            $this->fail('CreatePerson should refuse the address.');
        } catch (DomainException $e) {
            $this->assertSame('That email address can\'t be used.', $e->getMessage());
        }

        $this->assertSame(0, User::query()->where('email', 'like', '%@invalid')->count());

        // So the shared record can always be made, and removal keeps working.
        $robin = User::factory()->create(['name' => 'Robin Example']);
        app(RemovePerson::class)->handle($this->coordinator, $robin);
        $this->assertSame('removed-person@invalid', User::removedPerson()->email);
    }

    public function test_the_removed_person_cannot_sign_in_or_get_email(): void
    {
        $removed = User::removedPerson();

        $this->assertFalse($removed->canSignIn());
        $this->assertNull($removed->routeNotificationForMail(new InvitationNotification('https://example.org')));

        $this->expectException(DomainException::class);
        app(InvitePerson::class)->handle($this->coordinator, $removed);
    }
}
