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
        $regular = $this->regularHow === null ? null : EmailText::line('shift_taken_lead.regular', ['taker' => $taker, 'how' => $this->regularHow, 'date' => $this->date]);

        if ($this->releaserFirstName !== null) {
            $mail = (new MailMessage)
                ->subject(EmailText::line('shift_taken_lead.subject_covered', ['area' => $this->area, 'date' => $this->date]))
                ->line($this->tookBack
                    ? EmailText::line('shift_taken_lead.took_back', ['taker' => $taker, 'area' => $this->area, 'when' => $this->when])
                    : EmailText::line('shift_taken_lead.covering', ['taker' => $taker, 'releaser' => $this->releaserFirstName, 'area' => $this->area, 'when' => $this->when]));

            if ($regular !== null) {
                $mail->line($regular);
            }
        } else {
            $mail = (new MailMessage)
                ->subject(EmailText::line('shift_taken_lead.subject_took', ['taker' => $taker, 'area' => $this->area, 'date' => $this->date]))
                ->line($regular ?? EmailText::line('shift_taken_lead.took_open', ['taker' => $taker, 'area' => $this->area, 'when' => $this->when]));
        }

        return $mail->action(EmailText::line('shift_taken_lead.action'), $this->url);
    }
}
