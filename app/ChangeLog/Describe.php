<?php

namespace App\ChangeLog;

use App\Calendar\PersonSearch;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\ChangeLogEntry;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Turns change log entries into sentences, without the actor ("Made Robin a shift lead for Front desk"), for the person page and the change log screen. Names are shown the way PersonSearch::displayName() shows them to the viewer, and a person whose account was removed is "Removed person". An entry the app has no sentence for gets a plain fallback, and an entry without old and new values is described from its action and details alone.
 */
final class Describe
{
    /**
     * @param  Collection<int, Area>|null  $areas  Every area keyed by id, so a screen listing many entries doesn't look each one up.
     * @param  Collection<int, int>|null  $visibleIds  The ids of the people the viewer may see, for the same reason. Leave both out to look things up per entry.
     */
    public static function entry(ChangeLogEntry $entry, User $viewer, ?Collection $areas = null, ?Collection $visibleIds = null): string
    {
        $person = self::person($entry, $viewer, $visibleIds);
        $self = $entry->actor_id !== null && $entry->actor_id === $entry->affected_user_id;
        $possessive = $person.'\'s';
        $details = $entry->details ?? [];
        $changes = $entry->changes ?? [];

        return match ($entry->action) {
            'role.granted' => 'Made '.$person.' '.self::roleWords($details, $areas),
            'role.revoked' => self::roleRevoked($details, $person, $areas),
            'user.created' => 'Added '.$person,
            'user.invited' => 'Invited '.$person,
            'user.invitation_resent' => 'Sent '.$person.' a new invitation',
            'user.accepted_invitation' => 'Accepted '.($self ? 'their' : $possessive).' invitation',
            'user.cleared' => 'Cleared '.$person.' for regular shifts'.match ($details['via'] ?? null) {
                'role.granted' => ' (with a role)',
                'import' => ' (from the import)',
                'migration' => ' (already held a role)',
                'example_seed' => ' (example data)',
                default => '',
            },
            'user.moved_back' => 'Moved '.$person.' back to shadowing',
            'user.deactivated' => 'Turned off '.$possessive.' account',
            'user.removed' => 'Removed a person\'s account',
            'user.email_changed' => 'Changed '.($self ? 'their' : $possessive).' email address',
            'user.email_settings_changed' => 'Turned '.(($changes['cover_request_emails']['new'] ?? true) ? 'on' : 'off').' cover request emails'.($self ? '' : ' for '.$person),
            'user.member_id_added' => 'Added a membership ID for '.$person,
            'user.password_set' => 'Set '.($self ? 'a' : $possessive).' password',
            'user.password_changed' => 'Changed '.($self ? 'their' : $possessive).' password',
            'user.password_reset' => 'Reset '.($self ? 'their' : $possessive).' password',
            'user.signed_out_everywhere' => 'Logged '.($self ? 'themselves' : $person).' out everywhere',
            'two_factor.enabled' => 'Turned on two-factor'.($self ? '' : ' for '.$person),
            'two_factor.disabled' => 'Turned off two-factor'.($self ? '' : ' for '.$person),
            'two_factor.reset' => 'Reset '.$possessive.' two-factor',
            'two_factor.recovery_codes_regenerated' => 'Made new recovery codes'.($self ? '' : ' for '.$person),
            'two_factor.recovery_code_used' => 'Used a recovery code to log in',
            'two_factor.email_code_used' => 'Used an emailed code to log in',
            'area.created' => 'Added the area '.($changes['name']['new'] ?? self::areaName($entry)),
            'area.updated' => self::areaUpdated($entry, $changes),
            'organization.created' => 'Set up the organization',
            'organization.updated' => 'Changed the settings',
            'shifts.generated' => self::shiftsGenerated($details),
            'slot.created' => 'Added the weekly slot for '.self::slotWords($entry->subject),
            'slot.updated' => 'Changed the weekly slot for '.self::slotWords($entry->subject),
            'regular.added' => ($self ? 'Became the regular for ' : 'Added '.$person.' as the regular for ').self::slotWords($entry->subject instanceof SlotRegular ? $entry->subject->weeklySlot : null),
            'regular.ended' => 'Ended '.$possessive.' time as the regular for '.self::slotWords($entry->subject instanceof SlotRegular ? $entry->subject->weeklySlot : null),
            'shift.created' => 'Added '.self::shiftWords($entry->subject),
            'shift.updated' => 'Changed '.self::shiftWords($entry->subject, true),
            'shift.cancelled' => 'Cancelled '.self::shiftWords($entry->subject, true),
            'shift.restored' => 'Brought back '.self::shiftWords($entry->subject, true),
            'assignment.added' => match ($details['via'] ?? null) {
                'take' => 'Took ',
                'shadow_signup' => 'Signed up to shadow ',
                default => 'Put '.$person.' on ',
            }.self::shiftWords($entry->subject, true),
            'assignment.type_changed' => ($self ? 'Moved' : 'Moved '.$person).' from shadowing to a regular place on '.self::shiftWords($entry->subject, true),
            'assignment.removed' => self::assignmentRemoved($entry, $details, $self ? 'their' : $possessive, $person),
            'assignment.no_show' => isset($changes['no_show_at']['new']) ? 'Marked '.$person.' as a no-show for '.self::shiftWords($entry->subject, true) : 'Undid the no-show for '.$person.' on '.self::shiftWords($entry->subject, true),
            'cover_request.created' => 'Cover request sent for '.self::shiftDay($entry->subject),
            'cover_request.resent' => 'Cover request sent again for '.self::shiftDay($entry->subject),
            'cover_request.filled' => 'Cover request for '.self::shiftDay($entry->subject).' filled by '.$person,
            'cover_request.escalated' => ucfirst(self::shiftDay($entry->subject)).' marked urgent',
            'change_notice.sent' => 'Asked '.$person.' whether the new time works for '.self::noticeShifts($entry, $details),
            'change_notice.confirmed' => ($self ? 'Said' : 'Said for '.$person.' that').' the new time works for '.self::noticeShifts($entry, $details),
            'change_notice.lead_told' => 'Told the leads that '.$person.' hasn\'t answered about the new time for '.self::noticeShifts($entry, $details),
            'closure.created' => self::closureWords($entry->subject),
            'closure.updated' => self::closureChanged($entry, $areas),
            'closure.removed' => self::closureRemoved($entry, $areas),
            default => str_starts_with($entry->action, 'user.')
                ? 'Changed '.$possessive.' account'
                : ucfirst(str_replace(['.', '_'], ' ', $entry->action)),
        };
    }

    /** The grey line under a sentence: what a field changed from and to, or the note a coordinator left when clearing someone, where showing that is safe. */
    public static function changes(ChangeLogEntry $entry): ?string
    {
        $changes = $entry->changes ?? [];

        if ($entry->action === 'user.email_changed' && isset($changes['email']['old'], $changes['email']['new'])) {
            return 'From '.$changes['email']['old'].' to '.$changes['email']['new'];
        }

        if ($entry->action === 'area.updated' && isset($changes['lead_name'])) {
            $name = fn (?string $value) => $value ?: 'Area lead';

            return 'From '.$name($changes['lead_name']['old'] ?? null).' to '.$name($changes['lead_name']['new'] ?? null);
        }

        if (in_array($entry->action, ['user.cleared', 'user.moved_back'], true) && isset($changes['note']['new'])) {
            return 'Note: '.$changes['note']['new'];
        }

        if (collect($changes)->contains(fn ($change) => ($change['hidden'] ?? false) === true)) {
            return 'Passwords are never shown, only that they changed.';
        }

        return null;
    }

    /** The person an entry is about, as this viewer may see them. */
    private static function person(ChangeLogEntry $entry, User $viewer, ?Collection $visibleIds): string
    {
        $person = $entry->affectedUser ?? ($entry->subject instanceof User ? $entry->subject : null);

        if ($person === null || $person->is_removed_person) {
            return ChangeLogEntry::REMOVED_PERSON;
        }

        if ($visibleIds !== null) {
            return $visibleIds->contains($person->id) ? $person->name : $person->shortName();
        }

        return PersonSearch::displayName($viewer, $person);
    }

    /** "a coordinator", "an admin", "a shift lead for Front desk". */
    private static function roleWords(array $details, ?Collection $areas): string
    {
        return self::roleName(Role::tryFrom($details['role'] ?? '') ?? Role::AreaLead, self::area($details, $areas));
    }

    /** The role in words with its article, for sentences and flash messages: "an admin", "a shift lead for Front desk". */
    public static function roleName(Role $role, ?Area $area = null): string
    {
        return match ($role) {
            Role::Admin => 'an admin',
            Role::Coordinator => 'a coordinator',
            Role::AreaLead => self::article($title = $area ? mb_strtolower($area->leadTitle()) : 'area lead').' '.$title.($area ? ' for '.$area->name : ''),
        };
    }

    private static function roleRevoked(array $details, string $person, ?Collection $areas): string
    {
        $role = match ($details['role'] ?? null) {
            Role::Admin->value => 'admin',
            Role::Coordinator->value => 'coordinator',
            default => self::leadTitle($details, $areas),
        };
        $area = ($details['role'] ?? null) === Role::AreaLead->value ? self::area($details, $areas) : null;

        return 'Took the '.$role.' role'.($area ? ' for '.$area->name : '').' away from '.$person;
    }

    private static function leadTitle(array $details, ?Collection $areas): string
    {
        $area = self::area($details, $areas);

        return $area ? mb_strtolower($area->leadTitle()) : 'area lead';
    }

    private static function area(array $details, ?Collection $areas): ?Area
    {
        if (! isset($details['area_id'])) {
            return null;
        }

        return $areas !== null ? $areas->get($details['area_id']) : Area::query()->find($details['area_id']);
    }

    private static function article(string $word): string
    {
        return in_array(mb_strtolower($word[0]), ['a', 'e', 'i', 'o', 'u'], true) ? 'an' : 'a';
    }

    private static function areaName(ChangeLogEntry $entry): string
    {
        return $entry->subject instanceof Area ? $entry->subject->name : 'an area';
    }

    private static function areaUpdated(ChangeLogEntry $entry, array $changes): string
    {
        if (isset($changes['name']['old'], $changes['name']['new'])) {
            return 'Renamed the area '.$changes['name']['old'].' to '.$changes['name']['new'];
        }

        $name = self::areaName($entry);

        return array_keys($changes) === ['lead_name'] ? 'Changed what '.$name.' calls its leads' : 'Changed the area '.$name;
    }

    private static function shiftsGenerated(array $details): string
    {
        $words = 'Created '.($details['created'] ?? 'some').' shifts';

        if (isset($details['from'], $details['to'])) {
            $words .= ' from '.CarbonImmutable::parse($details['from'])->format('M j').' to '.CarbonImmutable::parse($details['to'])->format('M j');
        }

        return $words;
    }

    /** "Front desk, Thursdays 6:30 to 9:30pm". */
    private static function slotWords(mixed $slot): string
    {
        if (! $slot instanceof WeeklySlot) {
            return 'a weekly slot';
        }

        return ($slot->area?->name ?? 'an area').', '.$slot->describe();
    }

    /** "a Front desk shift on Thursday, October 22, 6:30 to 9:30pm", or with $the, "the Front desk shift on ...". */
    private static function shiftWords(mixed $shift, bool $the = false): string
    {
        if (! $shift instanceof Shift) {
            return 'a shift';
        }

        return ($the ? 'the ' : 'a ').($shift->area?->name ?? 'area').' shift on '.$shift->whenText();
    }

    /** "Removed Robin from the ...", or, when the person released it themselves, "Released their place on the ..." or "Gave up their shadow spot on the ...". */
    private static function assignmentRemoved(ChangeLogEntry $entry, array $details, string $whose, string $person): string
    {
        $shift = self::shiftWords($entry->subject, true);

        if (! str_starts_with($details['via'] ?? '', 'release:')) {
            return 'Removed '.$person.' from '.$shift;
        }

        return ($details['type'] ?? null) === 'shadow' ? 'Gave up '.$whose.' shadow spot on '.$shift : 'Released '.$whose.' place on '.$shift;
    }

    /** "the Front desk shift on Thursday, October 22, 6 to 9pm", or "3 shifts" when the notice covered several. */
    private static function noticeShifts(ChangeLogEntry $entry, array $details): string
    {
        $count = (int) ($details['count'] ?? 1);

        return $count > 1 ? $count.' shifts' : self::shiftWords($entry->subject, true);
    }

    /** "Front desk, Thursday, October 22", without the time. */
    private static function shiftDay(mixed $shift): string
    {
        if (! $shift instanceof Shift) {
            return 'a shift';
        }

        return ($shift->area?->name ?? 'area').', '.$shift->local_date->format('l, F j');
    }

    /** "Changed the Front desk closure to Nov 25 to Nov 28", from the days the entry saved, so it still reads right after a later change or removal. */
    private static function closureChanged(ChangeLogEntry $entry, ?Collection $areas): string
    {
        $details = $entry->details ?? [];

        if (! isset($details['starts_on'], $details['ends_on'])) {
            return 'Changed a closure';
        }

        return 'Changed the '.self::closureArea($entry, $areas).' closure to '.self::closureDays($details['starts_on'], $details['ends_on']);
    }

    /** "Removed the Front desk closure for Nov 25 to Nov 28", from the entry's old values, since the row is gone. */
    private static function closureRemoved(ChangeLogEntry $entry, ?Collection $areas): string
    {
        $changes = $entry->changes ?? [];

        if (! isset($changes['starts_on']['old'], $changes['ends_on']['old'])) {
            return 'Removed a closure';
        }

        return 'Removed the '.self::closureArea($entry, $areas).' closure for '.self::closureDays($changes['starts_on']['old'], $changes['ends_on']['old']);
    }

    private static function closureArea(ChangeLogEntry $entry, ?Collection $areas): string
    {
        $id = $entry->details['area_id'] ?? null;
        $area = $id === null ? null : ($areas !== null ? $areas->get($id) : Area::query()->find($id));

        return $area?->name ?? 'area';
    }

    /** "Nov 25 to Nov 28", or "Nov 25" for one day. */
    private static function closureDays(string $first, string $last): string
    {
        $from = CarbonImmutable::parse($first)->format('M j');
        $to = CarbonImmutable::parse($last)->format('M j');

        return $from === $to ? $from : $from.' to '.$to;
    }

    private static function closureWords(mixed $closure): string
    {
        if (! $closure instanceof AreaClosure) {
            return 'Closed an area';
        }

        $format = fn ($date) => $date->format('M j');

        return 'Closed '.($closure->area?->name ?? 'an area').' from '.$format($closure->starts_on).' to '.$format($closure->ends_on);
    }
}
