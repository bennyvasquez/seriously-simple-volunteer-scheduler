<?php

namespace Tests\Feature\Security;

use App\Actions\Accounts\GrantRole;
use App\Actions\Accounts\ImportPeople;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Import\HelloClubExport;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\CoverRequestNotification;
use App\Rules\NoWebAddress;
use App\Support\PersonName;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class NoWebAddressesTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const LINK = 'See https://x.example for details';

    private Area $frontDesk;

    private User $coordinator;

    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        $this->withoutDefer();
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', 'America/Detroit'));
        Organization::factory()->create(['name' => 'Example Makerspace', 'timezone' => 'America/Detroit', 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->coordinator = User::factory()->create();
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Admin);
        $this->enableTwoFactor($this->coordinator);
    }

    private function shift(): Shift
    {
        return app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, '2026-10-22', '18:30', '21:30', 1, 1, null);
    }

    public function test_a_release_note_with_a_web_address_goes_back_to_the_form_and_sends_nothing(): void
    {
        $sam = User::factory()->cleared()->create();
        $cleared = User::factory()->cleared()->create();
        $shift = $this->shift();
        Assignment::create(['shift_id' => $shift->id, 'user_id' => $sam->id, 'type' => AssignmentType::Regular]);

        $this->actingAs($sam)->post(route('shifts.release', $shift), ['scope' => 'one', 'note' => 'Sign in at https://x.example'])
            ->assertRedirect(route('shifts.release', $shift))
            ->assertSessionHasErrors(['note' => NoWebAddress::MESSAGE]);

        Notification::assertNotSentTo($cleared, CoverRequestNotification::class);
        $this->assertSame(1, $shift->assignments()->count());
    }

    public function test_a_shift_title_with_a_web_address_is_refused_on_every_form_that_sets_one(): void
    {
        $shift = $this->shift();

        $this->actingAs($this->coordinator)->post(route('calendar.shift.update', $shift), ['start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0, 'title' => self::LINK])
            ->assertSessionHasErrors(['title' => NoWebAddress::MESSAGE]);

        $this->actingAs($this->coordinator)->post(route('calendar.add.store'), ['kind' => 'shift', 'area' => $this->frontDesk->id, 'title' => self::LINK, 'date' => '2026-10-23', 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0])
            ->assertSessionHasErrors(['title' => NoWebAddress::MESSAGE]);

        $this->actingAs($this->coordinator)->post(route('calendar.slots.store'), ['area' => $this->frontDesk->id, 'title' => self::LINK, 'weekday' => 4, 'start_time' => '18:30', 'end_time' => '21:30', 'people_needed' => 1, 'shadow_spots' => 0, 'frequency' => 'weekly', 'fills_by' => 'regulars', 'valid_from' => '2026-10-22'])
            ->assertSessionHasErrors(['title' => NoWebAddress::MESSAGE]);

        $this->assertNull($shift->fresh()->title);
    }

    public function test_a_cancel_reason_with_a_web_address_is_refused(): void
    {
        $shift = $this->shift();

        $this->actingAs($this->coordinator)->post(route('calendar.shift.cancel', $shift), ['reason' => self::LINK])
            ->assertSessionHasErrors(['reason' => NoWebAddress::MESSAGE]);

        $this->assertNull($shift->fresh()->cancelled_at);
    }

    public function test_a_closure_reason_with_a_web_address_is_refused(): void
    {
        $this->actingAs($this->coordinator)->post(route('calendar.closures.store'), ['area' => [$this->frontDesk->id], 'from' => '2026-11-26', 'to' => '2026-11-29', 'reason' => self::LINK])
            ->assertSessionHasErrors(['reason' => NoWebAddress::MESSAGE]);

        $this->assertDatabaseCount('area_closures', 0);
    }

    public function test_an_area_name_and_a_lead_name_with_a_web_address_are_refused(): void
    {
        $this->actingAs($this->coordinator)->post(route('calendar.areas.store'), ['name' => 'Desk www.x.example'])
            ->assertSessionHasErrors(['name' => NoWebAddress::MESSAGE]);

        $this->actingAs($this->coordinator)->post(route('calendar.areas.update', $this->frontDesk), ['name' => 'Front desk', 'color' => 'teal', 'lead_name' => 'Lead https://x.example'])
            ->assertSessionHasErrors(['lead_name' => NoWebAddress::MESSAGE]);

        $this->actingAs($this->coordinator)->post(route('calendar.areas.update', $this->frontDesk), ['name' => 'https://x.example', 'color' => 'teal'])
            ->assertSessionHasErrors(['name' => NoWebAddress::MESSAGE]);

        $this->assertSame('Front desk', $this->frontDesk->fresh()->name);
    }

    public function test_the_organization_name_with_a_web_address_is_refused(): void
    {
        $this->actingAs($this->coordinator)->put(route('settings'), ['name' => 'Example https://x.example', 'timezone' => 'America/Detroit', 'weeks_ahead' => 4])
            ->assertSessionHasErrors(['name' => NoWebAddress::MESSAGE]);

        $this->assertSame('Example Makerspace', Organization::current()->name);
    }

    public function test_an_invite_with_link_characters_in_the_name_is_refused(): void
    {
        $this->actingAs($this->coordinator)->post(route('people.invite.store'), ['name' => '[x](https://y)', 'email' => 'new@example.org', 'starts_as' => 'volunteer'])
            ->assertSessionHasErrors(['name' => PersonName::REFUSAL]);

        $this->assertDatabaseMissing('users', ['email' => 'new@example.org']);
    }

    public function test_an_invite_cleans_the_name_it_stores(): void
    {
        $this->actingAs($this->coordinator)->post(route('people.invite.store'), ['name' => "Robin\u{00A0}\u{00A0}Example\u{200B}", 'email' => 'new@example.org', 'starts_as' => 'volunteer'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Robin Example', User::query()->where('email', 'new@example.org')->value('name'));
    }

    public function test_import_skips_a_row_with_link_characters_in_the_name_and_imports_the_rest(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, "Full name,Email,Profile ID\nAvery Example,avery@example.org,a1\n[x](https://y),bad@example.org,a2\n");

        try {
            $preview = app(ImportPeople::class)->preview(HelloClubExport::read($path), null);
        } finally {
            unlink($path);
        }

        $this->assertSame([PersonName::REFUSAL], $preview->problems->pluck('reason')->all());
        $this->assertSame(['avery@example.org'], $preview->add->map(fn ($item) => $item->row->email)->values()->all());
    }

    public function test_import_adds_a_name_with_a_stray_tab_cleaned(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'ssvs');
        file_put_contents($path, "Full name,Email,Profile ID\nSam\tExample,sam@example.org,a3\n");

        try {
            $import = app(ImportPeople::class);
            $preview = $import->preview(HelloClubExport::read($path), null);
            $this->assertCount(0, $preview->problems);
            $this->assertSame(1, $import->apply($this->coordinator, $preview, null));
        } finally {
            unlink($path);
        }

        $this->assertSame('Sam Example', User::query()->where('email', 'sam@example.org')->value('name'));
    }
}
