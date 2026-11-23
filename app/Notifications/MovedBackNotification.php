<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;

/** Sent to someone a coordinator moved back to shadowing (MoveBackToShadowing). Names the coordinator by first name only. When their shifts were released it lists them and the weekly slots they're no longer the regular on; it replaces the "You're off" email a release would send. The coordinator's note stays in the change log and isn't emailed. */
class MovedBackNotification extends Notification
{
    public string $coordinatorFirstName;

    public string $organization;

    /** @var list<string> "Front desk, Thursday, October 22, 6:30 to 9:30pm" for each shift released. */
    public array $shifts;

    /**
     * @param  Collection<int, Shift>  $shifts  The shifts released, with their areas loaded.
     * @param  list<string>  $noLongerRegularOn  The slots whose regular they no longer are, as "Thursdays, Front desk".
     */
    public function __construct(User $coordinator, Organization $organization, public int $required, Collection $shifts, public array $noLongerRegularOn)
    {
        $this->coordinatorFirstName = $coordinator->firstName();
        $this->organization = $organization->name;
        $this->shifts = $shifts->map(fn (Shift $shift) => $shift->area->name.', '.$shift->whenText())->values()->all();
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
            $mail->line('These shifts were given to other volunteers:');

            foreach ($this->shifts as $shift) {
                $mail->line($shift);
            }
        }

        if ($this->noLongerRegularOn !== []) {
            $mail->line('You\'re no longer the regular on '.Arr::join($this->noLongerRegularOn, '; ', ' and ').'.');
        }

        return $mail;
    }
}
