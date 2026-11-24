<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Tells a volunteer they're off the shifts they released (wireframe 41). One email per action, listing every shift for a range. Shadow spots the person left in the same action are listed too; leaving only a shadow spot sends no email, since the screen confirms it. It names the shadows who were told their spot is on hold, and says the person stays the regular when they still are for those dates. When they gave up their weekly slot (decision 13), it says they're no longer the regular from that date instead, and it's sent even when none of the slot's shifts from that date are on the calendar yet. A shift that still has the people it needs after they came off it is named as one where nobody else was asked, and there's nothing to take back there.
 */
class ShiftsReleasedNotification extends Notification
{
    /** @var list<array{area: string, date: string, weekday: string, when: string, shadows: list<string>, regular: bool, shadowSpot: bool, nobodyAsked: bool}> */
    public array $shifts;

    /**
     * @param  Collection<int, Shift>  $shifts  With their areas loaded.
     * @param  array<int, list<string>>  $shadowsTold  The first names of the shadows told, keyed by shift id.
     * @param  list<int>  $stillRegularOn  The ids of the shifts whose weekly slot the person stays the regular for.
     * @param  list<int>  $shadowSpots  The ids of the shifts where the person left a shadow spot.
     * @param  array{weekday: string, from: string}|null  $gaveUpSlot  The weekday of the slot the person gave up and the first date they're no longer its regular ("Thursday, October 29").
     * @param  list<int>  $nobodyAsked  The ids of the shifts that still have the people they need, so nobody was asked to cover them.
     */
    public function __construct(Collection $shifts, array $shadowsTold = [], array $stillRegularOn = [], array $shadowSpots = [], public ?array $gaveUpSlot = null, array $nobodyAsked = [])
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => [
            'area' => $shift->area->name,
            'date' => $shift->local_date->format('l, F j'),
            'weekday' => $shift->local_date->format('l'),
            'when' => $shift->whenText(),
            'shadows' => $shadowsTold[$shift->id] ?? [],
            'regular' => in_array($shift->id, $stillRegularOn, true),
            'shadowSpot' => in_array($shift->id, $shadowSpots, true),
            'nobodyAsked' => in_array($shift->id, $nobodyAsked, true),
        ])->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting(EmailText::line('shifts_released.greeting', ['first_name' => $notifiable->firstName()]));
        $noLongerRegular = $this->gaveUpSlot === null ? null : EmailText::line('shifts_released.no_longer_regular', ['weekday' => $this->gaveUpSlot['weekday'], 'from' => $this->gaveUpSlot['from']]);
        $action = EmailText::line('shifts_released.action');

        if ($this->shifts === [] && $noLongerRegular !== null) {
            return $mail->subject(EmailText::line('shifts_released.subject_no_longer_regular', ['weekday' => $this->gaveUpSlot['weekday']]))
                ->line($noLongerRegular)
                ->line(EmailText::line('shifts_released.none_on_calendar'))
                ->action($action, url('/'));
        }

        if (count($this->shifts) === 1) {
            $shift = $this->shifts[0];
            $mail->subject(EmailText::line('shifts_released.subject.one', ['date' => $shift['date']]))
                ->line(EmailText::line('shifts_released.off_shift', ['area' => $shift['area'], 'when' => $shift['when']]))
                ->line($shift['nobodyAsked'] ? EmailText::line('shifts_released.nobody_asked', ['area' => $shift['area']]) : EmailText::line('shifts_released.asked_one'));

            if ($shift['shadows'] !== []) {
                $mail->line(EmailText::choice('shifts_released.shadows_told', count($shift['shadows']), ['names' => self::names($shift['shadows'])]));
            }

            if ($noLongerRegular !== null) {
                $mail->line($noLongerRegular);
            } elseif ($shift['regular']) {
                $mail->line(EmailText::line('shifts_released.stay_regular', ['weekday' => $shift['weekday'], 'date' => $shift['date']]));
            }

            if (! $shift['nobodyAsked']) {
                $mail->line(EmailText::choice('shifts_released.take_back', 1));
            }

            return $mail->action($action, url('/'));
        }

        $mail->subject(EmailText::line('shifts_released.subject.other', ['count' => count($this->shifts)]))
            ->line(EmailText::line('shifts_released.list_intro'));

        foreach ($this->shifts as $shift) {
            $mail->line($shift['shadowSpot'] ? EmailText::line('shifts_released.shadow_spot_item', ['date' => $shift['date'], 'area' => $shift['area']]) : EmailText::line('shifts_released.shift_item', ['area' => $shift['area'], 'when' => $shift['when']]));
        }

        $nobodyAsked = collect($this->shifts)->where('nobodyAsked', true);
        $asked = collect($this->shifts)->where('nobodyAsked', false)->where('shadowSpot', false);

        foreach ($nobodyAsked as $shift) {
            $mail->line(EmailText::line('shifts_released.nobody_asked_on_date', ['area' => $shift['area'], 'date' => $shift['date']]));
        }

        if ($asked->isNotEmpty()) {
            $mail->line(EmailText::line($nobodyAsked->isEmpty() ? 'shifts_released.asked_all' : 'shifts_released.asked_rest'));
        }

        // One line per group of shadows, naming the dates unless it's every one of them.
        $byShadows = collect($this->shifts)->filter(fn (array $shift) => $shift['shadows'] !== [])->groupBy(fn (array $shift) => implode("\n", $shift['shadows']));

        foreach ($byShadows as $group) {
            $shadows = $group->first()['shadows'];
            $names = self::names($shadows);
            $mail->line(count($group) === count($this->shifts)
                ? EmailText::choice('shifts_released.shadows_told_every_date', count($shadows), ['names' => $names])
                : EmailText::choice('shifts_released.shadows_told_on_dates', count($shadows), ['names' => $names, 'dates' => Arr::join($group->pluck('date')->all(), ', ', ' and ')]));
        }

        $weekdays = collect($this->shifts)->where('regular', true)->pluck('weekday')->unique()->values();

        if ($noLongerRegular !== null) {
            $mail->line($noLongerRegular);
        } elseif ($weekdays->isNotEmpty()) {
            $mail->line($weekdays->count() === 1 ? EmailText::line('shifts_released.stay_regular_weekday', ['weekday' => $weekdays[0]]) : EmailText::line('shifts_released.stay_regular_weekly'));
        }

        if ($asked->isNotEmpty()) {
            $mail->line(EmailText::choice('shifts_released.take_back', count($this->shifts)));
        }

        return $mail->action($action, url('/'));
    }

    /** "Casey", "Casey and Jo". */
    private static function names(array $names): string
    {
        return Arr::join($names, ', ', ' and ');
    }
}
