<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/**
 * Tells a volunteer they're off the shifts they released (wireframe 41). One email per action, listing every shift for a range. It names the shadows who were told their spot is on hold, and says the person stays the regular when they still are for those dates.
 */
class ShiftsReleasedNotification extends Notification
{
    /** @var list<array{area: string, date: string, weekday: string, when: string, shadows: list<string>, regular: bool}> */
    public array $shifts;

    /**
     * @param  Collection<int, Shift>  $shifts  With their areas loaded.
     * @param  array<int, list<string>>  $shadowsTold  The first names of the shadows told, keyed by shift id.
     * @param  list<int>  $stillRegularOn  The ids of the shifts whose weekly slot the person stays the regular for.
     */
    public function __construct(Collection $shifts, array $shadowsTold = [], array $stillRegularOn = [])
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => [
            'area' => $shift->area->name,
            'date' => $shift->local_date->format('l, F j'),
            'weekday' => $shift->local_date->format('l'),
            'when' => $shift->whenText(),
            'shadows' => $shadowsTold[$shift->id] ?? [],
            'regular' => in_array($shift->id, $stillRegularOn, true),
        ])->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)->greeting('Hi '.$notifiable->firstName().',');

        if (count($this->shifts) === 1) {
            $shift = $this->shifts[0];
            $mail->subject('You\'re off '.$shift['date'])
                ->line('You\'re off your '.$shift['area'].' shift on '.$shift['when'].'.')
                ->line('We\'ve asked other volunteers to cover it, and we\'ll email you when someone does.');

            if ($shift['shadows'] !== []) {
                $mail->line(self::names($shift['shadows']).' going to shadow you, so we\'ve told them too.');
            }

            if ($shift['regular']) {
                $mail->line('You stay the '.$shift['weekday'].' regular. This only changes '.$shift['date'].'.');
            }

            return $mail->line('If you can make it after all, you can take it back from your home page while it\'s still open.')
                ->action('Open my shifts', url('/'));
        }

        $mail->subject('You\'re off '.count($this->shifts).' shifts')
            ->line('You\'re off these shifts:');

        foreach ($this->shifts as $shift) {
            $mail->line($shift['area'].', '.$shift['when']);
        }

        $mail->line('We\'ve asked other volunteers to cover them, and we\'ll email you when someone does.');

        // One line per group of shadows, naming the dates unless it's every one of them.
        $byShadows = collect($this->shifts)->filter(fn (array $shift) => $shift['shadows'] !== [])->groupBy(fn (array $shift) => implode("\n", $shift['shadows']));

        foreach ($byShadows as $group) {
            $dates = count($group) === count($this->shifts) ? 'these dates' : Arr::join($group->pluck('date')->all(), ', ', ' and ');
            $mail->line(self::names($group->first()['shadows']).' going to shadow you on '.$dates.', so we\'ve told them too.');
        }

        $weekdays = collect($this->shifts)->where('regular', true)->pluck('weekday')->unique()->values();

        if ($weekdays->isNotEmpty()) {
            $mail->line(($weekdays->count() === 1 ? 'You stay the '.$weekdays[0].' regular.' : 'You stay the regular on your weekly shifts.').' This only changes these dates.');
        }

        return $mail->line('If you can make any of them after all, you can take them back from your home page while they\'re still open.')
            ->action('Open my shifts', url('/'));
    }

    /** "Casey was", "Casey and Jo were". */
    private static function names(array $names): string
    {
        return Arr::join($names, ', ', ' and ').(count($names) === 1 ? ' was' : ' were');
    }
}
