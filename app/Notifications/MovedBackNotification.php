<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Sent to someone a coordinator moved back to shadowing (MoveBackToShadowing). Names the coordinator by first name and last initial. When their shifts were released it lists them, split into the ones other volunteers were asked to cover and the ones that still have the people they need (where nobody was asked), and the weekly slots they're no longer the regular on; it replaces the "You're off" email a release would send. The coordinator's note stays in the change log and isn't emailed. */
class MovedBackNotification extends Notification
{
    public string $coordinatorFirstName;

    public string $organization;

    /** @var list<string> "Front desk, Thursday, October 22, 6:30 to 9:30pm" for each shift released that became a cover request. */
    public array $shifts;

    /** @var list<string> The same, for each shift released that still has the people it needs, so nobody was asked. */
    public array $nobodyAsked;

    /**
     * @param  Collection<int, Shift>  $shifts  The shifts released, with their areas loaded.
     * @param  list<string>  $noLongerRegularOn  The slots whose regular they no longer are, as "Thursdays, Front desk".
     * @param  list<int>  $nobodyAskedIds  The ids of the shifts in `$shifts` that still have the people they need, where nobody was asked.
     */
    public function __construct(User $coordinator, Organization $organization, public int $required, Collection $shifts, public array $noLongerRegularOn, array $nobodyAskedIds = [])
    {
        $this->coordinatorFirstName = $coordinator->shortName();
        $this->organization = $organization->name;
        [$nobodyAsked, $asked] = $shifts->partition(fn (Shift $shift) => in_array($shift->id, $nobodyAskedIds, true));
        $words = fn (Shift $shift) => $shift->area->name.', '.$shift->whenText();
        $this->shifts = $asked->map($words)->values()->all();
        $this->nobodyAsked = $nobodyAsked->map($words)->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject('You\'ve been moved back to shadowing')
            ->greeting('Hi '.$notifiable->firstName().',')
            ->line($this->coordinatorFirstName.' moved you back to shadowing at '.$this->organization.'. You can sign up for shadow spots from Home, and a coordinator will review you again once you\'ve done '.$this->required.' shadow '.($this->required === 1 ? 'shift' : 'shifts').'.');

        if ($this->shifts !== []) {
            $mail->line('You\'re off these shifts, and other volunteers were asked to cover them:');

            foreach ($this->shifts as $shift) {
                $mail->line($shift);
            }
        }

        if ($this->nobodyAsked !== []) {
            $mail->line('You\'re off these shifts too. They still have the people they need, so nobody was asked:');

            foreach ($this->nobodyAsked as $shift) {
                $mail->line($shift);
            }
        }

        if ($this->noLongerRegularOn !== []) {
            $mail->line('You\'re no longer the regular on '.Arr::join($this->noLongerRegularOn, '; ', ' and ').'.');
        }

        return $mail;
    }
}
