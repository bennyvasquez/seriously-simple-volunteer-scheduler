<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an area's active leads that a volunteer took one of its shifts (decision 18, wireframe 47): "Covered" when it filled a cover request (`$releaserFirstName` set), or "{name} took" for any other open place. `$regularHow` ("Thursday" or "every other Thursday") is set when they became the regular from that date, and `$tookBack` when the releaser took their own shift back.
 */
class ShiftTakenLeadNotification extends Notification
{
    public string $area;

    public string $date;

    public string $when;

    public string $url;

    public function __construct(Shift $shift, public string $takerFirstName, public ?string $releaserFirstName = null, public bool $tookBack = false, public ?string $regularHow = null)
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
        $this->when = $shift->whenText();
        $this->url = route('calendar.shift', $shift);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $taker = $this->takerFirstName;
        $regular = $this->regularHow === null ? null : $taker.' is now the '.$this->regularHow.' regular from '.$this->date.'.';

        if ($this->releaserFirstName !== null) {
            $mail = (new MailMessage)
                ->subject('Covered: '.$this->area.' on '.$this->date)
                ->line($this->tookBack
                    ? $taker.' took back their '.$this->area.' shift on '.$this->when.'.'
                    : $taker.' is covering '.$this->releaserFirstName.'\'s '.$this->area.' shift on '.$this->when.'.');

            if ($regular !== null) {
                $mail->line($regular);
            }
        } else {
            $mail = (new MailMessage)
                ->subject($taker.' took '.$this->area.' on '.$this->date)
                ->line($regular ?? $taker.' took the open '.$this->area.' shift on '.$this->when.'.');
        }

        return $mail->action('Open the shift', $this->url);
    }
}
