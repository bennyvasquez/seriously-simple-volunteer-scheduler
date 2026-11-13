<?php

namespace Tests\Feature\People;

use App\ChangeLog\Describe;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DescribeTest extends TestCase
{
    use RefreshDatabase;

    private Area $frontDesk;

    private User $coordinator;

    private User $robin;

    protected function setUp(): void
    {
        parent::setUp();

        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->coordinator = User::factory()->create(['name' => 'Alex Coordinator']);
        $this->coordinator->roles()->create(['role' => Role::Coordinator->value]);
        $this->robin = User::factory()->create(['name' => 'Robin Example', 'email' => 'robin@example.org']);
    }

    private function say(ChangeLogEntry $entry, ?User $viewer = null): string
    {
        return Describe::entry($entry->fresh(), $viewer ?? $this->coordinator);
    }

    public function test_role_entries_use_the_areas_lead_name(): void
    {
        $granted = ChangeLogEntry::record($this->coordinator, 'role.granted', $this->robin, ['role' => 'area_lead', 'area_id' => $this->frontDesk->id]);
        $this->assertSame('Made Robin Example a shift lead for Front desk', $this->say($granted));

        $other = Area::factory()->create(['name' => 'Outreach', 'lead_name' => null]);
        $this->assertSame('Made Robin Example an area lead for Outreach', $this->say(ChangeLogEntry::record($this->coordinator, 'role.granted', $this->robin, ['role' => 'area_lead', 'area_id' => $other->id])));
        $this->assertSame('Made Robin Example an admin', $this->say(ChangeLogEntry::record($this->coordinator, 'role.granted', $this->robin, ['role' => 'admin'])));
        $this->assertSame('Made Robin Example a coordinator', $this->say(ChangeLogEntry::record($this->coordinator, 'role.granted', $this->robin, ['role' => 'coordinator'])));
        $this->assertSame('Took the shift lead role for Front desk away from Robin Example', $this->say(ChangeLogEntry::record($this->coordinator, 'role.revoked', $this->robin, ['role' => 'area_lead', 'area_id' => $this->frontDesk->id])));
        $this->assertSame('Took the coordinator role away from Robin Example', $this->say(ChangeLogEntry::record($this->coordinator, 'role.revoked', $this->robin, ['role' => 'coordinator'])));
    }

    public function test_account_entries(): void
    {
        $say = fn (string $action, ?User $actor = null, array $changes = []) => $this->say(ChangeLogEntry::record($actor ?? $this->coordinator, $action, $this->robin, changes: $changes));

        $this->assertSame('Added Robin Example', $say('user.created'));
        $this->assertSame('Invited Robin Example', $say('user.invited'));
        $this->assertSame('Invited Robin Example', $say('user.invited', changes: ['invited_at' => ['old' => null, 'new' => '2026-11-03 10:00:00']]));
        $this->assertSame('Invited Robin Example', $say('user.invited', changes: ['name' => ['old' => null, 'new' => 'Robin Example'], 'email' => ['old' => null, 'new' => 'robin@example.org']]));
        $this->assertSame('Sent Robin Example a new invitation', $say('user.invitation_resent'));
        $this->assertSame('Accepted their invitation', $say('user.accepted_invitation', $this->robin));
        $this->assertSame('Turned off Robin Example\'s account', $say('user.deactivated'));
        $this->assertSame('Changed their email address', $say('user.email_changed', $this->robin));
        $this->assertSame('Added a membership ID for Robin Example', $say('user.member_id_added'));
        $this->assertSame('Set a password', $say('user.password_set', $this->robin));
        $this->assertSame('Changed their password', $say('user.password_changed', $this->robin));
        $this->assertSame('Reset their password', $say('user.password_reset', $this->robin));
        $this->assertSame('Logged Robin Example out everywhere', $say('user.signed_out_everywhere'));
        $this->assertSame('Logged themselves out everywhere', $say('user.signed_out_everywhere', $this->robin));
        $this->assertSame('Removed a person\'s account', $say('user.removed'));
    }

    public function test_two_factor_entries(): void
    {
        $say = fn (string $action, ?User $actor = null) => $this->say(ChangeLogEntry::record($actor ?? $this->robin, $action, $this->robin));

        $this->assertSame('Turned on two-factor', $say('two_factor.enabled'));
        $this->assertSame('Turned off two-factor', $say('two_factor.disabled'));
        $this->assertSame('Made new recovery codes', $say('two_factor.recovery_codes_regenerated'));
        $this->assertSame('Used a recovery code to log in', $say('two_factor.recovery_code_used'));
        $this->assertSame('Used an emailed code to log in', $say('two_factor.email_code_used'));
        $this->assertSame('Reset Robin Example\'s two-factor', $say('two_factor.reset', $this->coordinator));
    }

    public function test_schedule_entries(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id]);
        $slotWords = 'Front desk, '.$slot->describe();

        $this->assertSame('Added the weekly slot for '.$slotWords, $this->say(ChangeLogEntry::record($this->coordinator, 'slot.created', $slot)));
        $this->assertSame('Changed the weekly slot for '.$slotWords, $this->say(ChangeLogEntry::record($this->coordinator, 'slot.updated', $slot)));

        $regular = SlotRegular::create(['weekly_slot_id' => $slot->id, 'user_id' => $this->robin->id, 'starts_on' => '2026-11-05']);
        $this->assertSame('Added Robin Example as the regular for '.$slotWords, $this->say(ChangeLogEntry::record($this->coordinator, 'regular.added', $regular, about: $this->robin)));

        $shift = Shift::query()->create([
            'area_id' => $this->frontDesk->id, 'local_date' => '2026-11-05',
            'starts_at' => CarbonImmutable::parse('2026-11-05 18:00', 'America/Detroit')->utc(), 'ends_at' => CarbonImmutable::parse('2026-11-05 21:00', 'America/Detroit')->utc(),
            'people_needed' => 1, 'shadow_spots' => 0,
        ]);
        $when = 'Thursday, November 5, 6 to 9pm';
        $this->assertSame('Added a Front desk shift on '.$when, $this->say(ChangeLogEntry::record($this->coordinator, 'shift.created', $shift)));
        $this->assertSame('Changed the Front desk shift on '.$when, $this->say(ChangeLogEntry::record($this->coordinator, 'shift.updated', $shift)));
        $this->assertSame('Cancelled the Front desk shift on '.$when, $this->say(ChangeLogEntry::record($this->coordinator, 'shift.cancelled', $shift)));
        $this->assertSame('Put Robin Example on the Front desk shift on '.$when, $this->say(ChangeLogEntry::record($this->coordinator, 'assignment.added', $shift, about: $this->robin)));
        $this->assertSame('Removed Robin Example from the Front desk shift on '.$when, $this->say(ChangeLogEntry::record($this->coordinator, 'assignment.removed', $shift, about: $this->robin)));
    }

    public function test_area_closure_and_settings_entries(): void
    {
        $this->assertSame('Added the area Front desk', $this->say(ChangeLogEntry::record($this->coordinator, 'area.created', $this->frontDesk, [], ['name' => ['old' => null, 'new' => 'Front desk']])));
        $this->assertSame('Renamed the area Front desk to Welcome desk', $this->say(ChangeLogEntry::record($this->coordinator, 'area.updated', $this->frontDesk, [], ['name' => ['old' => 'Front desk', 'new' => 'Welcome desk']])));
        $this->assertSame('Changed what Front desk calls its leads', $this->say(ChangeLogEntry::record($this->coordinator, 'area.updated', $this->frontDesk, [], ['lead_name' => ['old' => null, 'new' => 'Shift lead']])));
        $this->assertSame('Changed the area Front desk', $this->say(ChangeLogEntry::record($this->coordinator, 'area.updated', $this->frontDesk, [], ['color' => ['old' => 'blue', 'new' => 'green']])));
        $this->assertSame('Changed the settings', $this->say(ChangeLogEntry::record($this->coordinator, 'organization.updated', Organization::current())));
        $this->assertSame('Set up the organization', $this->say(ChangeLogEntry::record(null, 'organization.created', Organization::current())));
        $this->assertSame('Created 12 shifts from Nov 5 to Nov 30', $this->say(ChangeLogEntry::record(null, 'shifts.generated', Organization::current(), ['created' => 12, 'from' => '2026-11-05', 'to' => '2026-11-30'])));

        $closure = AreaClosure::create(['area_id' => $this->frontDesk->id, 'starts_on' => '2026-11-26', 'ends_on' => '2026-11-27', 'reason' => 'Holiday']);
        $this->assertSame('Closed Front desk from Nov 26 to Nov 27', $this->say(ChangeLogEntry::record($this->coordinator, 'closure.created', $closure)));
    }

    public function test_names_follow_what_the_viewer_may_see(): void
    {
        $lead = User::factory()->create(['name' => 'Casey Lead']);
        $lead->roles()->create(['role' => Role::AreaLead->value, 'area_id' => $this->frontDesk->id]);
        $entry = ChangeLogEntry::record($this->coordinator, 'user.invited', $this->robin);

        $this->assertSame('Invited Robin E.', $this->say($entry, $lead));

        $shift = Shift::query()->create(['area_id' => $this->frontDesk->id, 'local_date' => '2026-11-05', 'starts_at' => '2026-11-05 23:00:00', 'ends_at' => '2026-11-06 02:00:00', 'people_needed' => 1, 'shadow_spots' => 0]);
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $this->robin->id, 'type' => AssignmentType::Regular]);
        $this->assertSame('Invited Robin Example', $this->say($entry, $lead));
    }

    public function test_a_removed_person_shows_as_removed_person(): void
    {
        $removed = User::factory()->removedPerson()->create();
        $entry = ChangeLogEntry::record($this->coordinator, 'user.deactivated', $removed);
        $this->assertSame('Turned off Removed person\'s account', $this->say($entry));

        $orphan = ChangeLogEntry::create(['actor_id' => $this->coordinator->id, 'actor_name' => 'Alex Coordinator', 'action' => 'role.granted', 'subject_type' => User::class, 'subject_id' => 999999, 'affected_user_id' => null, 'details' => ['role' => 'admin']]);
        $this->assertSame('Made Removed person an admin', $this->say($orphan));
    }

    public function test_unknown_actions_fall_back_to_words(): void
    {
        $this->assertSame('Changed Robin Example\'s account', $this->say(ChangeLogEntry::record($this->coordinator, 'user.something_new', $this->robin)));
        $this->assertSame('Widget got polished', $this->say(ChangeLogEntry::record($this->coordinator, 'widget.got_polished', $this->frontDesk)));
    }

    public function test_changes_lines(): void
    {
        $email = ChangeLogEntry::record($this->robin, 'user.email_changed', $this->robin, changes: ['email' => ['old' => 'robin@example.org', 'new' => 'robin.new@example.org']]);
        $this->assertSame('From robin@example.org to robin.new@example.org', Describe::changes($email->fresh()));

        $scrubbed = ChangeLogEntry::record($this->robin, 'user.email_changed', $this->robin, changes: ['email' => ['old' => null, 'new' => null, 'removed' => true]]);
        $this->assertNull(Describe::changes($scrubbed->fresh()));

        $lead = ChangeLogEntry::record($this->coordinator, 'area.updated', $this->frontDesk, [], ['lead_name' => ['old' => null, 'new' => 'Shift lead']]);
        $this->assertSame('From Area lead to Shift lead', Describe::changes($lead->fresh()));

        $password = ChangeLogEntry::record($this->robin, 'user.password_changed', $this->robin, changes: ['password' => ['hidden' => true]]);
        $this->assertSame('Passwords are never shown, only that they changed.', Describe::changes($password->fresh()));

        $this->assertNull(Describe::changes(ChangeLogEntry::record($this->coordinator, 'user.invited', $this->robin)->fresh()));
    }
}
