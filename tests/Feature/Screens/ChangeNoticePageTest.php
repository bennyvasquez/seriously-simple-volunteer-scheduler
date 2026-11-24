<?php

namespace Tests\Feature\Screens;

use App\Actions\Accounts\GrantRole;
use App\Actions\Scheduling\CreateOneTimeShift;
use App\Enums\AssignmentType;
use App\Enums\Role;
use App\Models\Area;
use App\Models\Assignment;
use App\Models\ChangeLogEntry;
use App\Models\ChangeNotice;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Models\WeeklySlot;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\InteractsWithTwoFactor;
use Tests\TestCase;

class ChangeNoticePageTest extends TestCase
{
    use InteractsWithTwoFactor, RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $coordinator;

    private User $sam;

    private User $jordan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutDefer();
        // Wednesday, October 14, 2026, 9am in Detroit.
        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['timezone' => self::TIMEZONE, 'weeks_ahead' => 8]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk']);
        $this->coordinator = User::factory()->create(['name' => 'Alex Coordinator']);
        app(GrantRole::class)->handle(null, $this->coordinator, Role::Coordinator);
        $this->enableTwoFactor($this->coordinator);
        $this->sam = User::factory()->cleared()->create(['name' => 'Sam Example']);
        $this->jordan = User::factory()->cleared()->create(['name' => 'Jordan Example']);
    }

    /** A shift on the date at the new time (5 to 8pm), with the person on it. */
    private function shift(string $date, ?User $person = null, AssignmentType $type = AssignmentType::Regular): Shift
    {
        $shift = app(CreateOneTimeShift::class)->handle($this->coordinator, $this->frontDesk, $date, '17:00', '20:00', 1, 1, null);

        if ($person !== null) {
            Assignment::query()->forceCreate(['shift_id' => $shift->id, 'user_id' => $person->id, 'type' => $type]);
        }

        return $shift;
    }

    /** A notice that the shift used to run 6:30 to 9:30pm. */
    private function notice(Shift $shift, User $person, ?string $batch = null, array $attributes = []): ChangeNotice
    {
        return ChangeNotice::query()->create($attributes + [
            'batch' => $batch ?? '01JCHANGEBATCH0000000000AA',
            'user_id' => $person->id,
            'shift_id' => $shift->id,
            'changed_by' => $this->coordinator->id,
            'was_starts_at' => CarbonImmutable::parse($shift->local_date->toDateString().' 18:30', self::TIMEZONE)->utc(),
            'was_ends_at' => CarbonImmutable::parse($shift->local_date->toDateString().' 21:30', self::TIMEZONE)->utc(),
        ]);
    }

    private function answer(ChangeNotice $notice): string
    {
        return route('changes.show', $notice->batch);
    }

    public function test_the_person_sees_the_question(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($shift, $this->sam);

        $response = $this->actingAs($this->sam)->get($this->answer($notice))->assertOk();

        $response->assertSeeInOrder([
            'Your Thursday Front desk shift changed',
            'New time',
            'Thursday, October 22',
            '5 to 8pm',
            'Was',
            '6:30 to 9:30pm',
            'Changed by Alex C.',
            'Does the new time still work for you?',
            'Still works for me',
            'I can\'t make the new time',
        ], false);
        $this->assertSame(1, substr_count($response->getContent(), '<h1'));
        $response->assertSee('we\'ll ask other volunteers to cover it', false);
    }

    public function test_confirming_records_and_logs_it(): void
    {
        $first = $this->shift('2026-10-22', $this->sam);
        $second = $this->shift('2026-10-29', $this->sam);
        $notice = $this->notice($first, $this->sam);
        $this->notice($second, $this->sam);

        $this->actingAs($this->sam)->post($this->answer($notice))->assertRedirect($this->answer($notice));

        $this->assertSame(2, ChangeNotice::query()->whereNotNull('confirmed_at')->count());
        $entry = ChangeLogEntry::query()->where('action', 'change_notice.confirmed')->sole();
        $this->assertSame($this->sam->id, $entry->actor_id);
        $this->assertSame($this->sam->id, $entry->affected_user_id);
        $this->assertSame($first->id, $entry->subject_id);
        $this->assertSame(['batch' => $notice->batch, 'count' => 2], array_intersect_key($entry->details, ['batch' => 1, 'count' => 1]));
        $this->assertStringNotContainsString('Sam', json_encode($entry->details));

        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('Thanks. You\'re still on at the new time.')
            ->assertSee('Back to home');
    }

    public function test_confirming_twice_confirms_once(): void
    {
        $notice = $this->notice($this->shift('2026-10-22', $this->sam), $this->sam);

        $this->actingAs($this->sam)->post($this->answer($notice));
        $confirmedAt = $notice->fresh()->confirmed_at;
        $this->travel(5)->minutes();
        $this->actingAs($this->sam)->post($this->answer($notice));

        $this->assertEquals($confirmedAt, $notice->fresh()->confirmed_at);
        $this->assertSame(1, ChangeLogEntry::query()->where('action', 'change_notice.confirmed')->count());

        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('You already said the new time works.')
            ->assertSee('I can\'t make it after all', false)
            ->assertSee('href="'.route('shifts.release', $notice->shift_id).'"', false);
    }

    public function test_an_older_notice_cant_be_confirmed(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $old = $this->notice($shift, $this->sam, '01JCHANGEBATCH0000000000AA', ['ended_at' => now()]);
        $new = $this->notice($shift, $this->sam, '01JCHANGEBATCH0000000000BB');

        $this->actingAs($this->sam)->post($this->answer($old))->assertRedirect($this->answer($old));

        $this->assertNull($old->fresh()->confirmed_at);
        $this->assertNull($new->fresh()->confirmed_at);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'change_notice.confirmed')->count());
        $this->actingAs($this->sam)->get($this->answer($old))->assertOk()
            ->assertSee('This shift has changed again since that email.')
            ->assertSee('Check the newest email, or open the shift for its current time.')
            ->assertSee('Open the shift');
    }

    public function test_someone_no_longer_on_the_shift_cant_confirm(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($shift, $this->sam, null, ['ended_at' => now()]);
        Assignment::query()->where('shift_id', $shift->id)->delete();

        $this->actingAs($this->sam)->post($this->answer($notice))->assertRedirect($this->answer($notice));

        $this->assertNull($notice->fresh()->confirmed_at);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'change_notice.confirmed')->count());
        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('You\'re not on this shift any more.')
            ->assertSee('href="'.route('home').'"', false);
    }

    public function test_a_started_shift_cant_be_confirmed(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($shift, $this->sam);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 17:30', self::TIMEZONE));

        $this->actingAs($this->sam)->post($this->answer($notice))->assertRedirect($this->answer($notice));

        $this->assertNull($notice->fresh()->confirmed_at);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'change_notice.confirmed')->count());
        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('This shift has already started.');
    }

    public function test_someone_elses_notice_is_not_found(): void
    {
        $notice = $this->notice($this->shift('2026-10-22', $this->sam), $this->sam);
        $made = route('changes.show', '01JNOSUCHBATCH00000000000');

        $other = $this->actingAs($this->jordan)->get($this->answer($notice));
        $none = $this->actingAs($this->jordan)->get($made);
        $this->assertSame(404, $other->getStatusCode());
        $this->assertSame($none->getStatusCode(), $other->getStatusCode());
        $this->assertSame($none->getContent(), $other->getContent());

        $this->actingAs($this->jordan)->post($this->answer($notice))->assertNotFound();
        $this->assertNull($notice->fresh()->confirmed_at);
        $this->assertSame(0, ChangeLogEntry::query()->where('action', 'change_notice.confirmed')->count());
    }

    public function test_a_guest_is_sent_to_log_in_with_the_answer_wording(): void
    {
        $notice = $this->notice($this->shift('2026-10-22', $this->sam), $this->sam);

        $this->get($this->answer($notice))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()
            ->assertSee('Log in to answer about your shift. Once you\'re in, we\'ll take you straight back to it.', false)
            ->assertDontSee('Log in to take this shift');
    }

    public function test_cant_make_it_links_to_release_for_the_earliest_shift(): void
    {
        $later = $this->shift('2026-10-29', $this->sam);
        $earlier = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($later, $this->sam);
        $this->notice($earlier, $this->sam);

        $response = $this->actingAs($this->sam)->get($this->answer($notice))->assertOk();

        $response->assertSee('Your Front desk shifts changed');
        $response->assertSee('href="'.route('shifts.release', $earlier).'"', false);
        $response->assertDontSee('href="'.route('shifts.release', $later).'"', false);
    }

    public function test_a_cancelled_shift_says_it_was_cancelled_not_that_it_started(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($shift, $this->sam);
        $shift->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Power outage'])->save();

        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('This shift was cancelled.')
            ->assertDontSee('This shift has already started.')
            ->assertSee('Back to home');
        $this->actingAs($this->sam)->post($this->answer($notice));
        $this->assertNull($notice->fresh()->confirmed_at);
    }

    public function test_a_started_shift_still_says_it_started_and_a_mix_with_a_cancelled_one_says_cancelled(): void
    {
        $started = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($started, $this->sam);
        $this->travelTo(CarbonImmutable::parse('2026-10-22 18:00', self::TIMEZONE));

        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('This shift has already started.')
            ->assertDontSee('This shift was cancelled.');

        $cancelled = $this->shift('2026-10-29', $this->sam);
        $this->notice($cancelled, $this->sam);
        $cancelled->forceFill(['cancelled_at' => now(), 'cancel_reason' => 'Power outage'])->save();

        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('This shift was cancelled.')
            ->assertDontSee('This shift has already started.');
    }

    public function test_the_changed_page_has_its_own_copy_and_the_replaced_page_keeps_check_the_newest_email(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $ended = $this->notice($shift, $this->sam, null, ['ended_at' => now()]);

        $this->actingAs($this->sam)->get($this->answer($ended))->assertOk()
            ->assertSee('You\'re on this shift at its current time. This email\'s question doesn\'t apply any more.', false)
            ->assertDontSee('Check the newest email')
            ->assertSee('Open the shift');

        // A newer notice for the same shift replaces it.
        $this->notice($shift, $this->sam, '01JCHANGEBATCH0000000000BB');
        $this->actingAs($this->sam)->get($this->answer($ended))->assertOk()
            ->assertSee('This shift has changed again since that email.')
            ->assertSee('Check the newest email, or open the shift for its current time.')
            ->assertDontSee('doesn\'t apply any more');
    }

    public function test_the_schedule_panel_says_which_shifts_when_the_batch_covers_more_than_this_one(): void
    {
        $slot = WeeklySlot::factory()->create(['area_id' => $this->frontDesk->id, 'weekday' => 4, 'start_time' => '17:00', 'end_time' => '20:00']);
        $dates = ['2026-10-22', '2026-10-29', '2026-11-05'];
        $shifts = collect($dates)->map(function (string $date) use ($slot) {
            $shift = $this->shift($date, $this->sam);
            $shift->forceFill(['weekly_slot_id' => $slot->id])->save();

            return $shift;
        });
        $shifts->each(fn (Shift $shift) => $this->notice($shift, $this->sam));

        $response = $this->actingAs($this->sam)->get(route('calendar.shift', $shifts[1]))->assertOk();
        $response->assertSee('The time changed for your Thursdays from October 22 to November 5. Does it still work for you?', false);

        // A gap (one date confirmed on its own) isn't a range, so it counts the shifts left.
        $shifts[1]->forceFill(['weekly_slot_id' => null])->save();
        $this->actingAs($this->sam)->get(route('calendar.shift', $shifts[0]))->assertOk()
            ->assertSee('The time changed for 3 of your shifts. Does it still work for you?', false);

        // One shift in the batch keeps the short wording.
        $single = $this->shift('2026-11-12', $this->sam);
        $this->notice($single, $this->sam, '01JCHANGEBATCH0000000000CC');
        $this->actingAs($this->sam)->get(route('calendar.shift', $single))->assertOk()
            ->assertSee('The time changed. Does it still work for you?', false);
    }

    public function test_home_says_how_many_shifts_when_the_batch_has_more_than_one(): void
    {
        $this->notice($this->shift('2026-10-22', $this->sam), $this->sam);
        $this->notice($this->shift('2026-10-29', $this->sam), $this->sam);

        $this->actingAs($this->sam)->get('/')->assertOk()
            ->assertSee('Now 5 to 8pm, was 6:30 to 9:30pm. 2 shifts, starting Thursday, October 22.', false);

        $this->notice($this->shift('2026-11-05', $this->sam), $this->sam, '01JCHANGEBATCH0000000000DD', ['was_starts_at' => CarbonImmutable::parse('2026-11-05 15:00', self::TIMEZONE)->utc(), 'was_ends_at' => CarbonImmutable::parse('2026-11-05 18:00', self::TIMEZONE)->utc()]);
        $this->actingAs($this->sam)->get('/')->assertOk()->assertSee('Your Thursday Front desk shift changed');

        $this->notice($this->shift('2026-11-12', $this->sam), $this->sam, '01JCHANGEBATCH0000000000DD', ['was_starts_at' => CarbonImmutable::parse('2026-11-12 14:00', self::TIMEZONE)->utc(), 'was_ends_at' => CarbonImmutable::parse('2026-11-12 17:00', self::TIMEZONE)->utc()]);
        $this->actingAs($this->sam)->get('/')->assertOk()
            ->assertSee('2 shifts changed:');
    }

    public function test_home_asks_about_an_open_notice_without_the_email(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($shift, $this->sam);

        $response = $this->actingAs($this->sam)->get('/')->assertOk();

        $response->assertSeeInOrder([
            'Your Thursday Front desk shift changed',
            'Now 5 to 8pm, was 6:30 to 9:30pm.',
            'Still works for me',
            'I can\'t make the new time',
            'Your next shifts',
        ], false);
        $response->assertSee('action="'.$this->answer($notice).'"', false);
        $response->assertSee('href="'.route('shifts.release', $shift).'"', false);
    }

    public function test_home_asks_a_shadow_who_is_not_cleared_too(): void
    {
        $avery = User::factory()->create(['name' => 'Avery Trainee']);
        $this->notice($this->shift('2026-10-22', $avery, AssignmentType::Shadow), $avery);

        $this->actingAs($avery)->get('/')->assertOk()
            ->assertSeeInOrder(['Your Thursday Front desk shift changed', 'Still works for me', 'Your next shadow shift'], false);
    }

    public function test_confirming_from_home_comes_back_with_the_thanks(): void
    {
        $notice = $this->notice($this->shift('2026-10-22', $this->sam), $this->sam);

        $this->actingAs($this->sam)->post($this->answer($notice), ['from' => 'home'])->assertRedirect(route('home'));
        $this->assertNotNull($notice->fresh()->confirmed_at);

        $this->actingAs($this->sam)->get('/')->assertOk()
            ->assertSee('Thanks. You\'re still on at the new time.')
            ->assertDontSee('Does it still work', false)
            ->assertDontSee('I can\'t make the new time');
    }

    public function test_home_stops_asking_once_confirmed_or_replaced(): void
    {
        $confirmed = $this->shift('2026-10-22', $this->sam);
        $this->notice($confirmed, $this->sam, null, ['confirmed_at' => now()]);
        $replaced = $this->shift('2026-10-29', $this->sam);
        $this->notice($replaced, $this->sam, '01JCHANGEBATCH0000000000BB', ['ended_at' => now()]);

        $this->actingAs($this->sam)->get('/')->assertOk()
            ->assertDontSee('shift changed')
            ->assertDontSee('I can\'t make the new time')
            ->assertDontSee('Time changed');
    }

    public function test_the_schedule_panel_asks_on_a_shift_with_an_open_notice(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $other = $this->shift('2026-10-29', $this->sam);
        $notice = $this->notice($shift, $this->sam);

        $response = $this->actingAs($this->sam)->get(route('calendar.shift', $shift))->assertOk();
        $response->assertSee('The time changed. Does it still work for you?', false);
        $response->assertSee('action="'.$this->answer($notice).'"', false);
        $response->assertSee('Still works for me');
        $response->assertSee('I can\'t make the new time', false);

        $this->actingAs($this->sam)->get(route('calendar.shift', $other))->assertOk()
            ->assertDontSee('The time changed');
        $this->actingAs($this->jordan)->get(route('calendar.shift', $shift))->assertOk()
            ->assertDontSee('The time changed');

        $this->actingAs($this->sam)->post($this->answer($notice), ['from' => 'shift', 'shift' => $shift->id])
            ->assertRedirect(route('calendar.shift', $shift));
        $this->actingAs($this->sam)->get(route('calendar.shift', $shift))->assertOk()
            ->assertSee('Thanks. You\'re still on at the new time.')
            ->assertDontSee('The time changed');
    }

    public function test_the_shift_card_says_time_changed(): void
    {
        $changed = $this->shift('2026-10-22', $this->sam);
        $this->shift('2026-10-29', $this->sam);
        $this->notice($changed, $this->sam);

        $this->actingAs($this->sam)->get('/')->assertOk()->assertSee('Time changed');
        $mine = $this->actingAs($this->sam)->get(route('my-shifts'))->assertOk();
        $this->assertSame(1, substr_count($mine->getContent(), 'Time changed'));
    }

    public function test_someone_elses_notice_costs_the_same_queries_as_a_made_up_one(): void
    {
        $notice = $this->notice($this->shift('2026-10-22', $this->sam), $this->sam);
        // The first request of a session also records last_seen_at, so warm it up before counting.
        $this->actingAs($this->jordan)->get(route('home'));
        $count = function (string $url): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->actingAs($this->jordan)->get($url)->assertNotFound();
            $queries = collect(DB::getQueryLog())->pluck('query');
            DB::disableQueryLog();

            return $queries->count();
        };

        $this->assertSame($count(route('changes.show', '01JNOSUCHBATCH00000000000')), $count($this->answer($notice)));
    }

    public function test_a_guest_posting_an_answer_gets_what_the_other_volunteer_forms_give(): void
    {
        $notice = $this->notice($this->shift('2026-10-22', $this->sam), $this->sam);
        $shift = $this->shift('2026-10-29');

        // A guest has no session, so no login and no CSRF token: whatever status the take and shadow forms give a guest, this form gives too. (The test kernel doesn't enforce CSRF, so the status isn't asserted as a number.)
        $take = $this->post(route('shifts.take', $shift))->getStatusCode();
        $shadow = $this->post(route('shifts.shadow', $shift))->getStatusCode();
        $this->post(route('changes.confirm', $notice->batch))->assertStatus($take);

        $this->assertSame($take, $shadow);
        $this->assertNull($notice->fresh()->confirmed_at);
    }

    public function test_someone_put_back_on_a_shift_after_leaving_sees_it_changed(): void
    {
        $shift = $this->shift('2026-10-22', $this->sam);
        $notice = $this->notice($shift, $this->sam, null, ['ended_at' => now()]);

        $this->actingAs($this->sam)->get($this->answer($notice))->assertOk()
            ->assertSee('This shift has changed since that email.')
            ->assertDontSee('You\'re not on this shift any more.', false)
            ->assertSee('Open the shift');
        $this->actingAs($this->sam)->post($this->answer($notice));
        $this->assertNull($notice->fresh()->confirmed_at);
    }
}
