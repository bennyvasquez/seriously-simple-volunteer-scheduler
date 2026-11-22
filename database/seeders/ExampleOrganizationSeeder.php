<?php

namespace Database\Seeders;

use App\Actions\Accounts\CreatePerson;
use App\Actions\Accounts\DeactivateUser;
use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\RemovePerson;
use App\Actions\Scheduling\AddRegular;
use App\Actions\Scheduling\CancelShift;
use App\Actions\Scheduling\CreateArea;
use App\Actions\Scheduling\CreateClosure;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Actions\Scheduling\CreateWeeklySlot;
use App\Actions\Scheduling\GenerateShifts;
use App\Actions\Scheduling\PutOnShift;
use App\Actions\Scheduling\ReleaseShifts;
use App\Actions\Scheduling\UpdateArea;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Enums\SlotFrequency;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Fortify\RecoveryCode;
use LogicException;
use RuntimeException;

/**
 * A populated example install, the one the browser tests start from and the one `php artisan db:seed` gives developers. Everything is built through the app's own actions, so the change log has entries for it too. Accepting an invitation, choosing a password and connecting an authenticator app happen in controllers and Fortify, not actions, so those steps are written here the same way the app writes them, with the same change log entries, and without sending invitation emails.
 *
 * Every name is made up and every address is at example.org. Everyone who has accepted their invitation shares one password, made at random on each run, printed at the end of the run and returned by password(); ADMIN, COORDINATOR and AREA_LEAD have two-factor on with TWO_FACTOR_SECRET. NEEDS_SETUP is an area lead with a password who hasn't connected an authenticator app yet. Some actions email people after the response, so seeding a development install puts a few example emails in Mailpit. Both kinds of take page have an example: Łucja's released place on the next Tuesday front desk shift that's at least a day away (with a note, and Rosa shadowing), and a Sunday front desk slot with no regular.
 *
 * It runs only when APP_ENV is local or testing, even with --force, because these accounts are described in the public code. Anything else, including a real server set to production or staging, is refused before anything is written.
 */
class ExampleOrganizationSeeder extends Seeder
{
    public const ADMIN = 'admin@example.org';

    public const COORDINATOR = 'coordinator@example.org';

    public const AREA_LEAD = 'area-lead@example.org';

    public const VOLUNTEER = 'volunteer@example.org';

    public const NEEDS_SETUP = 'needs-setup@example.org';

    public const NOT_INVITED = 'not-invited@example.org';

    public const TURNED_OFF = 'turned-off@example.org';

    /** The environments the example organization may be seeded in. */
    public const ALLOWED_ENVIRONMENTS = ['local', 'testing'];

    /** The authenticator app secret for ADMIN, COORDINATOR and AREA_LEAD (base32, so any authenticator library can make codes from it). It stays fixed so developers add it to their authenticator app once rather than after every seed; with a random password and every address at example.org, where login links, password resets and emailed codes go unread, it opens nothing by itself. */
    public const TWO_FACTOR_SECRET = 'EXAMPLESECRETFORBROWSERTESTS2345';

    /** The active volunteers besides VOLUNTEER, by email. Names include letters like Ł and Ş, a long name and a one-word name. */
    private const VOLUNTEERS = [
        'lucja@example.org' => 'Łucja Nowak',
        'sebnem@example.org' => 'Şebnem Aydın',
        'maximiliana@example.org' => 'Maximiliana Featherstonehaugh-Worthington-Abernathy',
        'sunny@example.org' => 'Sunny',
        'theo@example.org' => 'Theo Park',
        'priya@example.org' => 'Priya Shah',
        'dana@example.org' => 'Dana Kowalski',
        'marcus@example.org' => 'Marcus Bell',
        'ines@example.org' => 'Inês Ferreira',
        'kai@example.org' => 'Kai Nakamura',
        'grace@example.org' => 'Grace Okafor',
        'owen@example.org' => 'Owen Tran',
        'rosa@example.org' => 'Rosa Delgado',
        'sam@example.org' => 'Sam Whitfield',
    ];

    /** The password from the latest run in this PHP process. The browser tests seed in their own process (Dusk's DatabaseTruncation runs db:seed there), so they read it here; the server they drive only checks the hash. */
    private static ?string $password = null;

    private string $passwordHash;

    private CarbonImmutable $today;

    /** The password everyone who accepted their invitation has, from the latest run in this PHP process. */
    public static function password(): string
    {
        return self::$password ?? throw new LogicException('The example organization hasn\'t been seeded in this process, so there is no example password yet.');
    }

    public function run(): void
    {
        if (! app()->environment(self::ALLOWED_ENVIRONMENTS)) {
            throw new RuntimeException('The example organization is only for development and tests, because its accounts are described in the public code. This install\'s APP_ENV is "'.app()->environment().'", so nothing was seeded. To set up a real organization, run php artisan ssvs:setup.');
        }

        // Letters and numbers only, so it's easy to copy or type when signing in by hand.
        self::$password = Str::password(20, symbols: false);

        // Hashed once: hashing it for every person would make each browser test noticeably slower to start.
        $this->passwordHash = Hash::make(self::$password);

        [$organization, $admin] = $this->organizationAndAdmin();
        $this->today = CarbonImmutable::parse($organization->today()->toDateString(), 'UTC');

        $frontDesk = app(CreateArea::class)->handle($admin, 'Front desk');
        app(UpdateArea::class)->handle($admin, $frontDesk, $frontDesk->name, $frontDesk->color, 'Shift lead');
        $woodshop = app(CreateArea::class)->handle($admin, 'Woodshop');
        $electronics = app(CreateArea::class)->handle($admin, 'Electronics');

        $coordinator = $this->person($admin, 'Jordan Ellis', self::COORDINATOR, twoFactor: true);
        app(GrantRole::class)->handle($admin, $coordinator, Role::Coordinator);

        $areaLead = $this->person($admin, 'Alex Rivera', self::AREA_LEAD, twoFactor: true);
        app(GrantRole::class)->handle($admin, $areaLead, Role::AreaLead, $frontDesk);

        $needsSetup = $this->person($admin, 'Pat Morgan', self::NEEDS_SETUP);
        app(GrantRole::class)->handle($admin, $needsSetup, Role::AreaLead, $electronics);

        $people = ['volunteer' => $this->person($admin, 'Jamie Lee', self::VOLUNTEER)];

        foreach (self::VOLUNTEERS as $email => $name) {
            $people[strtok($email, '@')] = $this->person($admin, $name, $email);
        }

        $turnedOff = $this->person($admin, 'Toby Marsh', self::TURNED_OFF);
        $leaving = $this->person($admin, 'Casey Moore', 'leaving@example.org');
        app(CreatePerson::class)->handle($admin, 'Harper Quinn', self::NOT_INVITED);

        // Weekly slots. Full ones have as many regulars as people needed; short ones have fewer.
        $slot = fn (Area $area, int $weekday, string $start, string $end, int $needed, int $shadows = 0, SlotFrequency $frequency = SlotFrequency::Weekly) => app(CreateWeeklySlot::class)->handle($admin, $area, [
            'weekday' => $weekday,
            'start_time' => $start,
            'end_time' => $end,
            'people_needed' => $needed,
            'shadow_spots' => $shadows,
            'frequency' => $frequency,
            'valid_from' => $frequency === SlotFrequency::EveryOtherWeek ? $this->next($weekday) : $this->today->toDateString(),
            'valid_until' => null,
            'title' => null,
            'is_rotation' => false,
        ]);

        $tuesdayDesk = $slot($frontDesk, 2, '17:00', '20:00', 2, 1);
        $thursdayDesk = $slot($frontDesk, 4, '17:00', '20:00', 2);
        $saturdayDesk = $slot($frontDesk, 6, '10:00', '14:00', 2, 0, SlotFrequency::EveryOtherWeek);
        $wednesdayShop = $slot($woodshop, 3, '18:00', '21:00', 3, 1);
        $saturdayShop = $slot($woodshop, 6, '13:00', '17:00', 2);
        $mondayBench = $slot($electronics, 1, '18:00', '21:00', 2);
        $fridayBench = $slot($electronics, 5, '18:00', '21:00', 1, 0, SlotFrequency::EveryOtherWeek);
        // No regular yet, so its dates offer "Every Sunday" on the take page.
        $slot($frontDesk, 0, '11:30', '14:30', 1);

        $regulars = [
            [$tuesdayDesk, [$people['volunteer'], $people['lucja']]],
            [$thursdayDesk, [$people['sebnem']]],
            [$saturdayDesk, [$people['theo'], $people['priya']]],
            [$wednesdayShop, [$people['maximiliana'], $people['dana'], $people['marcus']]],
            [$saturdayShop, [$people['sunny'], $turnedOff]],
            [$mondayBench, [$people['ines'], $leaving]],
            [$fridayBench, [$people['grace']]],
        ];

        foreach ($regulars as [$weeklySlot, $members]) {
            foreach ($members as $member) {
                app(AddRegular::class)->handle($admin, $weeklySlot, $member, $weeklySlot->valid_from->toDateString());
            }
        }

        $openHouse = app(CreateOneTimeShift::class)->handle($admin, $frontDesk, $this->today->addDays(10)->toDateString(), '12:00', '16:00', 4, 0, 'Open house');
        app(PutOnShift::class)->handle($admin, $openHouse, $people['owen'], AssignmentType::Regular);

        $workshop = app(CreateOneTimeShift::class)->handle($admin, $electronics, $this->today->addDays(18)->toDateString(), '10:00', '13:00', 2, 0, 'Soldering workshop');
        app(PutOnShift::class)->handle($admin, $workshop, $people['kai'], AssignmentType::Regular);

        // A new volunteer shadowing on the next Tuesday front desk shift that's at least a day away.
        $shadowed = $this->shiftOf($tuesdayDesk, 1);
        app(PutOnShift::class)->handle($admin, $shadowed, $people['rosa'], AssignmentType::Shadow);

        // Łucja can't make that shift, so it's a cover request with a note and a shadow on it.
        app(ReleaseShifts::class)->one($people['lucja'], $shadowed, 'The register key is in the top drawer.');

        // The Woodshop is closed for its whole third week, which cancels the shifts in it.
        app(CreateClosure::class)->handle($admin, [$woodshop->id], $this->today->addDays(14)->toDateString(), $this->today->addDays(20)->toDateString(), 'Floor refinishing');

        app(CancelShift::class)->handle($admin, $this->shiftOf($mondayBench, 7), 'Not enough volunteers that night');

        // The nightly run, so the window is filled the way a live install's is. The slots already made their shifts, so this normally adds none.
        app(GenerateShifts::class)->handle($organization);

        app(DeactivateUser::class)->handle($admin, $turnedOff);
        app(RemovePerson::class)->handle($admin, $leaving);

        $this->command?->getOutput()->writeln([
            '',
            '  The example accounts\' password for this seed is: '.self::$password,
            '  Admin: '.self::ADMIN.', coordinator: '.self::COORDINATOR.', area lead: '.self::AREA_LEAD.', volunteer: '.self::VOLUNTEER,
            '',
        ]);
    }

    /** The organization and its first admin, made the way `ssvs:setup` makes them, by the system. */
    private function organizationAndAdmin(): array
    {
        return DB::transaction(function () {
            $organization = new Organization(['name' => 'Example Makerspace', 'timezone' => 'America/Detroit', 'weeks_ahead' => 4]);
            $changes = ChangeLogEntry::changesOf($organization);
            $organization->save();
            ChangeLogEntry::record(null, 'organization.created', $organization, changes: $changes);

            $admin = app(CreatePerson::class)->handle(null, 'Morgan Reyes', self::ADMIN);
            app(GrantRole::class)->handle(null, $admin, Role::Admin);
            $this->acceptInvitation(null, $admin, twoFactor: true);

            return [$organization, $admin];
        });
    }

    /** Adds someone and takes them through accepting their invitation. */
    private function person(User $admin, string $name, string $email, bool $twoFactor = false): User
    {
        $person = app(CreatePerson::class)->handle($admin, $name, $email);
        $this->acceptInvitation($admin, $person, $twoFactor);

        return $person;
    }

    /** Invites someone, accepts the invitation as them, sets their password and, for staff, connects the authenticator app, logging each step the way InvitePerson, LoginLinkController, InitialPasswordController and Fortify's confirmation event do. */
    private function acceptInvitation(?User $inviter, User $person, bool $twoFactor): void
    {
        DB::transaction(function () use ($inviter, $person, $twoFactor) {
            $person->forceFill(['invited_at' => now()]);
            $changes = ChangeLogEntry::changesOf($person);
            $person->save();
            ChangeLogEntry::record($inviter, 'user.invited', $person, changes: $changes);

            $person->forceFill(['email_verified_at' => now()]);
            $changes = ChangeLogEntry::changesOf($person);
            $person->save();
            ChangeLogEntry::record($person, 'user.accepted_invitation', $person, changes: $changes);

            $person->forceFill(['password' => $this->passwordHash]);
            $changes = ChangeLogEntry::changesOf($person);
            $person->save();
            ChangeLogEntry::record($person, 'user.password_set', $person, changes: $changes);

            if ($twoFactor) {
                $person->forceFill([
                    'two_factor_secret' => Fortify::currentEncrypter()->encrypt(self::TWO_FACTOR_SECRET),
                    'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(collect(range(1, 8))->map(fn () => RecoveryCode::generate())->all())),
                    'two_factor_confirmed_at' => now(),
                ])->save();
                ChangeLogEntry::record($person, 'two_factor.enabled', $person);
            }
        });
    }

    /** The first date on or after today that falls on a weekday (0 is Sunday). */
    private function next(int $weekday): string
    {
        return $this->today->addDays(($weekday - $this->today->dayOfWeek + 7) % 7)->toDateString();
    }

    /** The slot's first shift that starts at least this many days from now. */
    private function shiftOf(WeeklySlot $slot, int $daysAway): Shift
    {
        return Shift::query()
            ->where('weekly_slot_id', $slot->id)
            ->whereNull('cancelled_at')
            ->where('starts_at', '>=', now()->addDays($daysAway))
            ->orderBy('starts_at')
            ->firstOrFail();
    }
}
