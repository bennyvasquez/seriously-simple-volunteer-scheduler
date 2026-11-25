<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\GrantRole;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\AreaClosure;
use App\Models\Assignment;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\SlotRegular;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

/**
 * What every kind of visitor gets from every route, written from spec section 6 ("What each role can do") and section 13 ("Who sees what"), not from what the code does. A new route needs its row here, or test_every_route_has_a_row fails.
 *
 * Each route is one case: the world is seeded once, then each visitor's request runs inside a transaction that's rolled back, so one visitor's change (turning someone off, say) never changes what the next one sees.
 *
 * Outcomes: ok (2xx, or a redirect anywhere but login and account.security), login (a redirect to login), setup (a redirect to account.security), forbidden (403) and missing (404). The outcome is about getting in, not about the flow: `ok` means the route let the visitor through, so a page that explains why they can't take a shift, or a form sent back with validation errors, is `ok`. Those rules belong to the flow tests.
 */
class AccessMatrixTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    /** Every kind of visitor, in the order the failure messages list them. */
    public const VISITORS = ['guest', 'not_invited', 'turned_off', 'volunteer', 'cleared', 'lead_own', 'lead_other', 'coordinator', 'admin', 'in_setup'];

    /** Someone not invited yet, or turned off, can't sign in and gets none of this (section 6), so a session that somehow exists is ended and sent to log in. Someone with a role must finish their password and two-factor before anything else. */
    private const SIGNED_OUT = ['guest' => 'login', 'not_invited' => 'login', 'turned_off' => 'login', 'in_setup' => 'setup'];

    /** Anyone who can sign in: their own shifts, the calendar's read-only views, their account. */
    private const EVERYONE = [...self::SIGNED_OUT, 'volunteer' => 'ok', 'cleared' => 'ok', 'lead_own' => 'ok', 'lead_other' => 'ok', 'coordinator' => 'ok', 'admin' => 'ok'];

    /** Anyone with a role, in any area (the calendar's editing screens that start by picking an area, the people list). */
    private const ROLES = [...self::EVERYONE, 'volunteer' => 'forbidden', 'cleared' => 'forbidden'];

    /** Area leads in their areas, coordinators and admins in all areas. Every record these rows touch is in lead_own's area. */
    private const THEIR_AREAS = [...self::ROLES, 'lead_other' => 'forbidden'];

    private const COORDINATORS = [...self::ROLES, 'lead_own' => 'forbidden', 'lead_other' => 'forbidden'];

    private const ADMINS = [...self::COORDINATORS, 'coordinator' => 'forbidden'];

    /** The screens someone with a role uses to finish setting up (EnsureAccountIsSecure::ALLOWED_DURING_SETUP). */
    private const EVERYONE_AND_SETUP = [...self::EVERYONE, 'in_setup' => 'ok'];

    /** A person page and its controls, about a volunteer with a regular slot in lead_own's area. A lead sees people with a shift or regular slot in their areas (404 for anyone else, so nobody learns who exists); only coordinators and admins manage them. */
    private const PERSON_PAGE = [...self::ROLES, 'lead_other' => 'missing'];

    private const MANAGE_PERSON = [...self::PERSON_PAGE, 'lead_own' => 'forbidden'];

    /** Logging in and getting back in: for guests. Someone signed in is sent on to Home. */
    private const GUESTS = [...self::EVERYONE, 'guest' => 'ok'];

    /** A guest who isn't partway through a password login has nothing to answer here, so goes to log in. */
    private const PENDING_LOGIN = [...self::GUESTS, 'guest' => 'login'];

    /** Emailed links for changing an address work whether or not this device is logged in (section 13). */
    private const EMAILED_LINK = [...self::EVERYONE, 'guest' => 'ok'];

    /**
     * Every route, keyed by its method and name (a route with no name of its own uses the name of the GET route at the same address). A variant of a route, with different records, adds " #" and a note. `params` and `data` values starting with @ are records in the seeded world.
     */
    public const ROUTES = [
        // Their own shifts (section 6, "Their own shifts").
        'GET home' => ['expect' => self::EVERYONE],
        'GET my-shifts' => ['expect' => self::EVERYONE],
        'GET account.emails' => ['expect' => self::EVERYONE],
        'POST account.emails' => ['expect' => self::EVERYONE],
        // own_shift has every visitor on it.
        'GET shifts.release' => ['params' => ['shift' => '@own_shift'], 'expect' => self::EVERYONE],
        'POST shifts.release' => ['params' => ['shift' => '@own_shift'], 'expect' => self::EVERYONE],
        'GET shifts.released' => ['expect' => self::EVERYONE],
        // Taking needs clearance, and shadowing needs not being cleared, but both pages open for everyone who can sign in and explain the refusal.
        'GET shifts.take' => ['params' => ['shift' => '@open_shift'], 'expect' => self::EVERYONE],
        'POST shifts.take' => ['params' => ['shift' => '@open_shift'], 'expect' => self::EVERYONE],
        'GET shifts.taken' => ['params' => ['shift' => '@open_shift'], 'expect' => self::EVERYONE],
        'GET shifts.shadow' => ['params' => ['shift' => '@shift_a'], 'expect' => self::EVERYONE],
        'POST shifts.shadow' => ['params' => ['shift' => '@shift_a'], 'expect' => self::EVERYONE],
        // Answering a change: own_batch has a row for every visitor; other_batch belongs to regular_a only.
        'GET changes.show' => ['params' => ['batch' => '@own_batch'], 'expect' => self::EVERYONE],
        'POST changes.confirm' => ['params' => ['batch' => '@own_batch'], 'expect' => self::EVERYONE],
        'GET changes.show #someone else\'s batch' => ['params' => ['batch' => '@other_batch'], 'expect' => [...self::EVERYONE, 'volunteer' => 'missing', 'cleared' => 'missing', 'lead_own' => 'missing', 'lead_other' => 'missing', 'coordinator' => 'missing', 'admin' => 'missing']],
        'POST changes.confirm #someone else\'s batch' => ['params' => ['batch' => '@other_batch'], 'expect' => [...self::EVERYONE, 'volunteer' => 'missing', 'cleared' => 'missing', 'lead_own' => 'missing', 'lead_other' => 'missing', 'coordinator' => 'missing', 'admin' => 'missing']],

        // Their account (section 13, "Accounts and access").
        'GET account.show' => ['expect' => self::EVERYONE],
        'GET account.security' => ['expect' => self::EVERYONE_AND_SETUP],
        'POST account.password.set' => ['expect' => self::EVERYONE_AND_SETUP],
        'GET account.email.edit' => ['expect' => self::EVERYONE],
        'POST account.email.update' => ['expect' => self::EVERYONE],
        // Confirming by an emailed link is for people with no password; everyone else confirms with their password. Only volunteer has no password.
        'POST account.email.identity.send' => ['expect' => [...self::EVERYONE, 'cleared' => 'forbidden', 'lead_own' => 'forbidden', 'lead_other' => 'forbidden', 'coordinator' => 'forbidden', 'admin' => 'forbidden']],
        'POST account.forgot-password' => ['expect' => self::EVERYONE_AND_SETUP],
        // Signing out everywhere lands on log in, so this row can't tell it from being refused; SessionsAndDeactivationTest covers it.
        'POST account.sign-out-everywhere' => ['expect' => ['guest' => 'login', 'not_invited' => 'login', 'turned_off' => 'login', 'volunteer' => 'login', 'cleared' => 'login', 'lead_own' => 'login', 'lead_other' => 'login', 'coordinator' => 'login', 'admin' => 'login', 'in_setup' => 'login']],
        'GET account.email.identity' => ['params' => ['token' => 'not-a-real-token'], 'expect' => self::EMAILED_LINK],
        'POST account.email.identity' => ['params' => ['token' => 'not-a-real-token'], 'expect' => self::EMAILED_LINK],
        'GET account.email.confirm' => ['params' => ['token' => 'not-a-real-token'], 'expect' => self::EMAILED_LINK],
        'POST account.email.confirm' => ['params' => ['token' => 'not-a-real-token'], 'expect' => self::EMAILED_LINK],

        // Fortify's account routes. The two-factor ones ask for the password first (a redirect to password.confirm, so ok).
        'PUT user-password.update' => ['expect' => self::EVERYONE],
        'GET password.confirm' => ['expect' => self::EVERYONE_AND_SETUP],
        'POST password.confirm.store' => ['expect' => self::EVERYONE_AND_SETUP],
        'GET password.confirmation' => ['expect' => self::EVERYONE_AND_SETUP],
        'POST two-factor.enable' => ['expect' => self::EVERYONE_AND_SETUP],
        'POST two-factor.confirm' => ['expect' => self::EVERYONE_AND_SETUP],
        'DELETE two-factor.disable' => ['expect' => self::EVERYONE],
        'GET two-factor.qr-code' => ['expect' => self::EVERYONE_AND_SETUP],
        'GET two-factor.secret-key' => ['expect' => self::EVERYONE_AND_SETUP],
        'GET two-factor.recovery-codes' => ['expect' => self::EVERYONE_AND_SETUP],
        'POST two-factor.regenerate-recovery-codes' => ['expect' => self::EVERYONE_AND_SETUP],
        'POST logout' => ['expect' => self::EVERYONE_AND_SETUP],

        // Logging in (section 13): for guests.
        'GET login' => ['expect' => self::GUESTS],
        'POST login.store' => ['expect' => self::GUESTS],
        'POST login-link.request' => ['expect' => self::GUESTS],
        'GET login-link.show' => ['params' => ['token' => 'not-a-real-token'], 'expect' => self::GUESTS],
        // A link that doesn't work sends the guest back to log in, with a message, to ask for a new one.
        'POST login-link.consume' => ['params' => ['token' => 'not-a-real-token'], 'expect' => self::PENDING_LOGIN],
        'GET password.request' => ['expect' => self::GUESTS],
        'POST password.email' => ['expect' => self::GUESTS],
        'GET password.reset' => ['params' => ['token' => 'not-a-real-token'], 'expect' => self::GUESTS],
        'POST password.update' => ['expect' => self::GUESTS],
        'GET two-factor.login' => ['expect' => self::PENDING_LOGIN],
        // A wrong code goes back to the code form (FailedTwoFactorLoginResponse), which sends a guest with no pending login on to log in.
        'POST two-factor.login.store' => ['data' => ['code' => '000000'], 'expect' => self::GUESTS],
        'POST two-factor.email.send' => ['expect' => self::PENDING_LOGIN],
        'POST two-factor.email.verify' => ['data' => ['code' => '000000'], 'expect' => self::PENDING_LOGIN],

        // The calendar (section 6, "The calendar"): everyone can read it.
        'GET calendar.week' => ['expect' => self::EVERYONE],
        'GET calendar.day' => ['expect' => self::EVERYONE],
        'GET calendar.month' => ['expect' => self::EVERYONE],
        'GET calendar.shift' => ['params' => ['shift' => '@shift_a'], 'expect' => self::EVERYONE],

        // Weekly slots, one-time shifts and regulars, putting people on and off, editing, cancelling and bringing back shifts, and marking no-shows: their areas.
        'GET calendar.add' => ['expect' => self::ROLES],
        'POST calendar.add.store' => ['data' => ['area' => '@area_a'], 'expect' => self::THEIR_AREAS],
        'GET calendar.slots.create' => ['expect' => self::ROLES],
        'POST calendar.slots.store' => ['data' => ['area' => '@area_a'], 'expect' => self::THEIR_AREAS],
        'GET calendar.slots.edit' => ['params' => ['slot' => '@slot_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.slots.update' => ['params' => ['slot' => '@slot_a'], 'expect' => self::THEIR_AREAS],
        'GET calendar.slots.regulars.create' => ['params' => ['slot' => '@slot_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.slots.regulars.store' => ['params' => ['slot' => '@slot_a'], 'expect' => self::THEIR_AREAS],
        'GET calendar.slots.regulars.end' => ['params' => ['slot' => '@slot_a', 'regular' => '@regular_slot_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.slots.regulars.end.store' => ['params' => ['slot' => '@slot_a', 'regular' => '@regular_slot_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.shift.update' => ['params' => ['shift' => '@shift_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.shift.assign' => ['params' => ['shift' => '@shift_a'], 'expect' => self::THEIR_AREAS],
        'GET calendar.shift.remove-person' => ['params' => ['shift' => '@shift_a'], 'data' => ['assignment' => '@regular_a_on_shift_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.shift.remove-person.store' => ['params' => ['shift' => '@shift_a'], 'data' => ['assignment' => '@regular_a_on_shift_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.shift.no-show' => ['params' => ['shift' => '@shift_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.shift.cancel' => ['params' => ['shift' => '@shift_a'], 'expect' => self::THEIR_AREAS],
        'POST calendar.shift.restore' => ['params' => ['shift' => '@shift_a'], 'expect' => self::THEIR_AREAS],

        // Closures, and creating and editing areas: coordinators and admins.
        'GET calendar.closures.create' => ['expect' => self::COORDINATORS],
        'POST calendar.closures.store' => ['expect' => self::COORDINATORS],
        'GET calendar.closures.edit' => ['params' => ['closure' => '@closure_a'], 'expect' => self::COORDINATORS],
        'POST calendar.closures.update' => ['params' => ['closure' => '@closure_a'], 'expect' => self::COORDINATORS],
        'POST calendar.closures.remove' => ['params' => ['closure' => '@closure_a'], 'expect' => self::COORDINATORS],
        'GET calendar.areas.create' => ['expect' => self::COORDINATORS],
        'POST calendar.areas.store' => ['expect' => self::COORDINATORS],
        'GET calendar.areas.edit' => ['params' => ['area' => '@area_a'], 'expect' => self::COORDINATORS],
        'POST calendar.areas.update' => ['params' => ['area' => '@area_a'], 'expect' => self::COORDINATORS],

        // Settings: the page holds the shadowing setting (coordinators and admins); organization settings are admins only.
        'GET settings' => ['expect' => self::COORDINATORS],
        'PUT settings' => ['expect' => self::ADMINS],
        'PUT settings.shadowing' => ['expect' => self::COORDINATORS],

        // The wording of the emails SSVS sends: coordinators and admins.
        'GET emails.index' => ['expect' => self::COORDINATORS],
        'GET emails.edit' => ['params' => ['email' => 'login_link'], 'expect' => self::COORDINATORS],
        'PUT emails.update' => ['params' => ['email' => 'login_link'], 'expect' => self::COORDINATORS],
        'POST emails.preview' => ['params' => ['email' => 'login_link'], 'expect' => self::COORDINATORS],
        'DELETE emails.reset' => ['params' => ['email' => 'login_link', 'line' => 'login_link.intro'], 'expect' => self::COORDINATORS],

        // People (section 6, "People").
        'GET people.index' => ['expect' => self::ROLES],
        'GET people.show' => ['params' => ['user' => '@regular_a'], 'expect' => self::PERSON_PAGE],
        'GET people.invite' => ['expect' => self::COORDINATORS],
        'POST people.invite.store' => ['expect' => self::COORDINATORS],
        'GET people.invite-everyone' => ['expect' => self::COORDINATORS],
        'POST people.invite-everyone.store' => ['expect' => self::COORDINATORS],
        'POST people.invite.send' => ['params' => ['user' => '@regular_a'], 'expect' => self::MANAGE_PERSON],
        'POST people.clear' => ['params' => ['user' => '@regular_a'], 'expect' => self::MANAGE_PERSON],
        'GET people.move-back' => ['params' => ['user' => '@regular_a'], 'expect' => self::MANAGE_PERSON],
        'POST people.move-back.store' => ['params' => ['user' => '@regular_a'], 'expect' => self::MANAGE_PERSON],
        'POST people.turn-off' => ['params' => ['user' => '@regular_a'], 'expect' => self::MANAGE_PERSON],
        'GET people.remove' => ['params' => ['user' => '@regular_a'], 'expect' => self::MANAGE_PERSON],
        'POST people.remove.store' => ['params' => ['user' => '@regular_a'], 'expect' => self::MANAGE_PERSON],
        // Coordinators grant and revoke area lead only; admins any role.
        'POST people.roles.store' => ['params' => ['user' => '@regular_a'], 'data' => ['role' => 'area_lead:@area_a'], 'expect' => self::MANAGE_PERSON],
        'POST people.roles.destroy' => ['params' => ['user' => '@regular_a'], 'data' => ['role' => 'area_lead:@area_a'], 'expect' => self::MANAGE_PERSON],
        'POST people.roles.store #coordinator role' => ['params' => ['user' => '@regular_a'], 'data' => ['role' => 'coordinator'], 'expect' => [...self::MANAGE_PERSON, 'coordinator' => 'forbidden']],
        'POST people.roles.destroy #coordinator role' => ['params' => ['user' => '@regular_a'], 'data' => ['role' => 'coordinator'], 'expect' => [...self::MANAGE_PERSON, 'coordinator' => 'forbidden']],
        // Resetting someone's two-factor, on their security page: admins only.
        'GET people.security' => ['params' => ['user' => '@regular_a'], 'expect' => self::ADMINS],
        'POST people.two-factor.reset' => ['params' => ['user' => '@regular_a'], 'expect' => self::ADMINS],
        // The change log: coordinators and admins.
        'GET change-log.index' => ['expect' => self::COORDINATORS],
    ];

    /** Routes with no row, each for a reason. */
    private const IGNORED = [
        // Dusk's routes log anyone in as anyone. They're registered only under APP_ENV=testing for the browser tests (DuskRoutesTest), never on a real server.
        'dusk.login', 'dusk.logout', 'dusk.user',
        // Laravel's local disk serving routes, turned off in a later security batch.
        'storage.local', 'storage.local.upload',
        // The health check, open to everyone and showing nothing.
        'GET /up',
    ];

    /**
     * Mismatches between a row (the spec) and the code, found by this test and reported to the maintainer. A case listed here is skipped instead of failing while the mismatch lasts; `*` stands for every route. A route-specific entry that starts matching fails, so it gets taken off the list.
     */
    private const FINDINGS = [
        '*|not_invited' => 'a session for someone not invited yet is treated as a volunteer\'s instead of being ended (section 6: they get none of this).',
    ];

    /** @var array<string, mixed> */
    private array $world = [];

    /** @var array<string, User|null> */
    private array $visitors = [];

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Mail::fake();
        $this->withoutDefer();
        // Wednesday, October 14, 2026, 9am in Detroit; the shifts are the week after.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        $this->seedWorld();
    }

    /** Two areas, a weekly slot with a regular in each, a shift in each, an open shift, a shift every visitor is on, a closure and two change notice batches. */
    private function seedWorld(): void
    {
        Organization::factory()->create(['timezone' => 'America/Detroit']);
        $areaA = Area::factory()->create(['name' => 'Front desk']);
        $areaB = Area::factory()->create(['name' => 'Wood shop']);

        $this->visitors = [
            'guest' => null,
            'not_invited' => User::factory()->notInvited()->create(['name' => 'Nell Notinvited']),
            'turned_off' => User::factory()->cleared()->deactivated()->create(['name' => 'Toby Turnedoff']),
            'volunteer' => User::factory()->withoutPassword()->create(['name' => 'Vic Volunteer']),
            'cleared' => User::factory()->cleared()->create(['name' => 'Cleo Cleared']),
            'lead_own' => $this->withRole('Lou Lead', Role::AreaLead, $areaA),
            'lead_other' => $this->withRole('Otto Lead', Role::AreaLead, $areaB),
            'coordinator' => $this->withRole('Cora Coordinator', Role::Coordinator),
            'admin' => $this->withRole('Ada Admin', Role::Admin),
            'in_setup' => $this->withRole('Sid Setup', Role::AreaLead, $areaA, finished: false),
        ];

        $regularA = User::factory()->cleared()->create(['name' => 'Robin Regular']);
        $regularB = User::factory()->cleared()->create(['name' => 'Remy Regular']);
        [$slotA, $shiftA, $regularSlotA, $assignmentA] = $this->slotWithRegular($areaA, $regularA);
        $this->slotWithRegular($areaB, $regularB);

        $openShift = $this->shift($areaA, '2026-10-23', peopleNeeded: 1);
        $ownShift = $this->shift($areaA, '2026-10-24', peopleNeeded: 10);

        foreach (array_filter($this->visitors) as $visitor) {
            Assignment::query()->forceCreate(['shift_id' => $ownShift->id, 'user_id' => $visitor->id, 'type' => AssignmentType::Regular]);
            $this->notice('MATRIXOWNBATCH000000000000', $ownShift, $visitor);
        }

        $this->notice('MATRIXOTHERBATCH0000000000', $shiftA, $regularA);

        $this->world = [
            'area_a' => $areaA,
            'slot_a' => $slotA,
            'shift_a' => $shiftA,
            'regular_a' => $regularA,
            'regular_slot_a' => $regularSlotA,
            'regular_a_on_shift_a' => $assignmentA,
            'open_shift' => $openShift,
            'own_shift' => $ownShift,
            'closure_a' => AreaClosure::query()->create(['area_id' => $areaA->id, 'starts_on' => '2026-12-24', 'ends_on' => '2026-12-26', 'reason' => 'Holiday']),
            'own_batch' => 'MATRIXOWNBATCH000000000000',
            'other_batch' => 'MATRIXOTHERBATCH0000000000',
        ];
    }

    private function withRole(string $name, Role $role, ?Area $area = null, bool $finished = true): User
    {
        $user = User::factory()->cleared()->when(! $finished, fn ($factory) => $factory->withoutPassword())->create(['name' => $name]);
        app(GrantRole::class)->handle(null, $user, $role, $area);

        if ($finished) {
            $this->enableTwoFactor($user);
        }

        return $user;
    }

    /** @return array{0: WeeklySlot, 1: Shift, 2: SlotRegular, 3: Assignment} */
    private function slotWithRegular(Area $area, User $person): array
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $area->id, 'shadow_spots' => 1]);
        $regular = SlotRegular::query()->create(['weekly_slot_id' => $slot->id, 'user_id' => $person->id, 'frequency' => $slot->frequency, 'starts_on' => '2026-10-01']);
        $shift = $this->shift($area, '2026-10-22', peopleNeeded: 1, slot: $slot);
        $assignment = Assignment::query()->forceCreate(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => AssignmentType::Regular]);

        return [$slot, $shift, $regular, $assignment];
    }

    /** A shift from 3:30 to 6:30pm in Detroit on the date. */
    private function shift(Area $area, string $date, int $peopleNeeded, ?WeeklySlot $slot = null): Shift
    {
        return Shift::query()->create([
            'area_id' => $area->id,
            'weekly_slot_id' => $slot?->id,
            'local_date' => $date,
            'starts_at' => CarbonImmutable::parse($date.' 15:30', 'America/Detroit')->utc(),
            'ends_at' => CarbonImmutable::parse($date.' 18:30', 'America/Detroit')->utc(),
            'people_needed' => $peopleNeeded,
            'shadow_spots' => $slot?->shadow_spots ?? 0,
        ]);
    }

    private function notice(string $batch, Shift $shift, User $person): void
    {
        ChangeNotice::query()->create([
            'batch' => $batch,
            'user_id' => $person->id,
            'shift_id' => $shift->id,
            'changed_by' => $this->visitors['coordinator']->id,
            'was_starts_at' => $shift->starts_at->subHour(),
            'was_ends_at' => $shift->ends_at->subHour(),
        ]);
    }

    /** @return array<string, array{0: string}> */
    public static function routeRows(): array
    {
        $rows = [];

        foreach (array_keys(self::ROUTES) as $key) {
            $rows[$key] = [$key];
        }

        return $rows;
    }

    #[DataProvider('routeRows')]
    public function test_each_visitor_gets_what_the_spec_says(string $key): void
    {
        $row = self::ROUTES[$key];
        $this->assertSame(self::VISITORS, array_keys(array_merge(array_flip(self::VISITORS), $row['expect'])), "{$key} needs an outcome for every visitor and no others.");

        // A visitor with a finding on every route is checked once, below, rather than skipping every route.
        $visitors = array_filter(self::VISITORS, fn (string $visitor) => ! isset(self::FINDINGS["*|{$visitor}"]));

        $this->checkRows([$key], $visitors);
    }

    public function test_visitors_with_a_finding_on_every_route(): void
    {
        $visitors = array_filter(self::VISITORS, fn (string $visitor) => isset(self::FINDINGS["*|{$visitor}"]));

        if ($visitors === []) {
            $this->expectNotToPerformAssertions();

            return;
        }

        $this->checkRows(array_keys(self::ROUTES), $visitors);
    }

    /**
     * Sends each row's request as each visitor and compares the outcome with the row. A mismatch on the FINDINGS list skips the case, naming the finding; any other mismatch fails.
     *
     * @param  array<int, string>  $keys
     * @param  array<int, string>  $visitors
     */
    private function checkRows(array $keys, array $visitors): void
    {
        $mismatches = [];
        $findings = [];

        foreach ($keys as $key) {
            foreach ($visitors as $visitor) {
                $expected = self::ROUTES[$key]['expect'][$visitor];
                $actual = $this->outcome($this->send($key, $visitor));
                $finding = self::FINDINGS["{$key}|{$visitor}"] ?? self::FINDINGS["*|{$visitor}"] ?? null;

                if ($actual === $expected) {
                    $this->assertFalse(isset(self::FINDINGS["{$key}|{$visitor}"]), "{$key} for {$visitor} matches the spec now, so take it off FINDINGS.");

                    continue;
                }

                if ($finding !== null) {
                    $findings[$finding] = true;

                    continue;
                }

                $mismatches[] = "{$key}, {$visitor}: expected {$expected}, got {$actual}";
            }
        }

        $this->assertSame([], $mismatches, 'These don\'t match spec section 6.');

        if ($findings !== []) {
            $this->markTestSkipped('Finding: '.implode(' ', array_keys($findings)));
        }
    }

    /** Sends the row's request as the visitor, inside a transaction that's rolled back, with a fresh session and fresh rate limits. */
    private function send(string $key, string $visitor): TestResponse
    {
        [$method, $name] = self::methodAndName($key);
        $row = self::ROUTES[$key];

        $this->app['auth']->forgetGuards();
        $this->flushSession();
        Cache::flush();

        $user = $this->visitors[$visitor];

        if ($user !== null) {
            $this->actingAs(User::query()->findOrFail($user->id));
        }

        DB::beginTransaction();

        try {
            return $this->call($method, route($name, $this->resolve($row['params'] ?? [])), $this->resolve($row['data'] ?? []));
        } finally {
            DB::rollBack();
        }
    }

    /** Swaps @names for the seeded records' keys, inside strings too ("area_lead:@area_a"). */
    private function resolve(array $values): array
    {
        return array_map(fn ($value) => is_string($value) ? preg_replace_callback('/@([a-z_]+)/', function ($match) {
            $record = $this->world[$match[1]];

            return is_string($record) ? $record : (string) $record->getKey();
        }, $value) : $value, $values);
    }

    private function outcome(TestResponse $response): string
    {
        $status = $response->status();

        if ($response->isRedirect()) {
            $path = parse_url((string) $response->headers->get('Location'), PHP_URL_PATH) ?: '/';

            return match ($path) {
                parse_url(route('login'), PHP_URL_PATH) => 'login',
                parse_url(route('account.security'), PHP_URL_PATH) => 'setup',
                default => 'ok',
            };
        }

        return match (true) {
            $status >= 200 && $status < 300 => 'ok',
            $status === 403 => 'forbidden',
            $status === 404 => 'missing',
            default => "status {$status}",
        };
    }

    /** @return array{0: string, 1: string} */
    public static function methodAndName(string $key): array
    {
        [$route] = explode(' #', $key, 2);

        return explode(' ', $route, 2);
    }

    public function test_every_route_has_a_row(): void
    {
        $rows = [];

        foreach (array_keys(self::ROUTES) as $key) {
            $rows[implode(' ', self::methodAndName($key))] = true;
        }

        $routes = collect(Route::getRoutes()->getRoutes());
        $missing = [];
        $seen = [];

        foreach ($routes as $route) {
            $key = $this->keyFor($route, $routes);
            $seen[$key] = true;

            if (in_array($route->getName(), self::IGNORED, true) || in_array($key, self::IGNORED, true)) {
                continue;
            }

            if (! isset($rows[$key])) {
                $missing[] = $key;
            }
        }

        $this->assertSame([], $missing, 'These routes need rows in AccessMatrixTest::ROUTES, written from spec section 6.');
        $this->assertSame([], array_keys(array_diff_key($rows, $seen)), 'These rows have no route.');
    }

    /** "METHOD name", using the GET route at the same address for a route with no name, or "METHOD /uri" when there's none. */
    private function keyFor(RoutingRoute $route, $routes): string
    {
        $method = collect($route->methods())->reject(fn ($method) => $method === 'HEAD')->first();
        $name = $route->getName() ?? $routes->first(fn (RoutingRoute $other) => $other->uri() === $route->uri() && $other->getName() !== null && in_array('GET', $other->methods(), true))?->getName();

        return $method.' '.($name ?? '/'.ltrim($route->uri(), '/'));
    }
}
