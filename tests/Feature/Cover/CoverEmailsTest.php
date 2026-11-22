<?php

namespace Tests\Feature\Cover;

use App\Enums\ShadowSpotNotice;
use App\Models\Area;
use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use App\Notifications\CoverRequestNotification;
use App\Notifications\ShadowSpotNotification;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Tests\TestCase;

class CoverEmailsTest extends TestCase
{
    use RefreshDatabase;

    private const TIMEZONE = 'America/Detroit';

    private Area $frontDesk;

    private User $morgan;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(CarbonImmutable::parse('2026-10-14 09:00', self::TIMEZONE));
        Organization::factory()->create(['name' => 'Example Makerspace', 'timezone' => self::TIMEZONE]);
        $this->frontDesk = Area::factory()->create(['name' => 'Front desk', 'lead_name' => 'Shift lead']);
        $this->morgan = User::factory()->create(['name' => 'Morgan Example']);
    }

    /** A Front desk shift, 6:30 to 9:30pm Detroit time, on the given date. */
    private function shift(string $date): Shift
    {
        $start = CarbonImmutable::parse($date.' 18:30', self::TIMEZONE)->utc();

        return Shift::query()->create(['area_id' => $this->frontDesk->id, 'local_date' => $date, 'starts_at' => $start, 'ends_at' => $start->addHours(3), 'people_needed' => 1, 'shadow_spots' => 1])->load('area');
    }

    private function text(MailMessage $mail): string
    {
        return implode("\n", array_merge([$mail->greeting], $mail->introLines, $mail->outroLines));
    }

    public function test_a_cover_request_for_one_shift(): void
    {
        $shift = $this->shift('2026-10-22');
        $mail = (new CoverRequestNotification(collect([$shift]), 'Robin', 'The key is in the drawer.'))->toMail($this->morgan);
        $text = $this->text($mail);

        $this->assertSame('Can you cover Front desk on Thursday, October 22?', $mail->subject);
        $this->assertStringContainsString('Hi Morgan,', $text);
        $this->assertStringContainsString('Robin can\'t make their Front desk shift. Can you take it?', $text);
        $this->assertStringContainsString('Front desk, Thursday, October 22, 6:30 to 9:30pm', $text);
        $this->assertStringContainsString('Note from Robin: The key is in the drawer.', $text);
        $this->assertSame('Take this shift', $mail->actionText);
        $this->assertSame(url('/shifts/'.$shift->id.'/take'), $mail->actionUrl);
        $this->assertStringContainsString('The first person to take it gets it. You\'ll log in first if you aren\'t already.', $text);
        $this->assertStringContainsString('You\'re getting this because you volunteer with Example Makerspace and cover request emails are on for you.', $text);
        $this->assertStringContainsString('[Stop getting cover requests]('.url('/account/emails').')', $text);
    }

    public function test_a_cover_request_without_a_note_leaves_the_note_out(): void
    {
        $mail = (new CoverRequestNotification(collect([$this->shift('2026-10-22')]), 'Robin', null))->toMail($this->morgan);

        $this->assertStringNotContainsString('Note from', $this->text($mail));
    }

    public function test_a_cover_request_for_several_shifts_lists_each_with_its_own_link(): void
    {
        $first = $this->shift('2026-10-22');
        $second = $this->shift('2026-10-29');
        $mail = (new CoverRequestNotification(collect([$first, $second]), 'Robin', null))->toMail($this->morgan);
        $text = $this->text($mail);

        $this->assertSame('Can you cover Front desk on 2 dates?', $mail->subject);
        $this->assertStringContainsString('Robin can\'t make their Front desk shifts. Can you take one?', $text);
        $this->assertStringContainsString('[Front desk, Thursday, October 22, 6:30 to 9:30pm]('.url('/shifts/'.$first->id.'/take').')', $text);
        $this->assertStringContainsString('[Front desk, Thursday, October 29, 6:30 to 9:30pm]('.url('/shifts/'.$second->id.'/take').')', $text);
        $this->assertStringContainsString('Stop getting cover requests', $text);
    }

    public function test_giving_up_a_slot_names_the_weekday(): void
    {
        $mail = (new CoverRequestNotification(collect([$this->shift('2026-10-22'), $this->shift('2026-10-29')]), 'Sam', null, gaveUpSlot: true))->toMail($this->morgan);
        $text = $this->text($mail);

        $this->assertSame('Can you cover Front desk on some Thursdays?', $mail->subject);
        $this->assertStringContainsString('Sam is giving up their weekly Thursday Front desk slot. These dates need someone:', $text);
        $this->assertStringContainsString('You\'re getting this because you volunteer with Example Makerspace and cover request emails are on for you.', $text);
    }

    public function test_the_shadow_on_hold_and_back_on_emails(): void
    {
        $shift = $this->shift('2026-10-22');

        $onHold = (new ShadowSpotNotification($shift, ShadowSpotNotice::OnHold, 'Robin'))->toMail($this->morgan);
        $this->assertSame('Your shadow shift on Thursday, October 22 is on hold', $onHold->subject);
        $this->assertStringContainsString('Robin can\'t make their Front desk shift on Thursday, October 22, so right now there\'s nobody for you to shadow.', $this->text($onHold));
        $this->assertStringContainsString('We\'ve asked other volunteers to cover it, and we\'ll email you as soon as someone does. You don\'t need to do anything.', $this->text($onHold));

        $backOn = (new ShadowSpotNotification($shift, ShadowSpotNotice::BackOn, 'Robin', 'Sam'))->toMail($this->morgan);
        $this->assertSame('Your shadow shift on Thursday, October 22 is back on', $backOn->subject);
        $this->assertStringContainsString('Sam is covering, so you\'ll shadow Sam. Front desk, Thursday, October 22, 6:30 to 9:30pm.', $this->text($backOn));

        $uncovered = (new ShadowSpotNotification($shift, ShadowSpotNotice::StillUncovered, 'Robin'))->toMail($this->morgan);
        $this->assertStringEndsWith('please don\'t come in for it.', $uncovered->introLines[1]);
    }
}
