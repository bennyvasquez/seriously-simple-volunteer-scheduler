<?php

namespace App\Notifications;

use App\Http\Controllers\Volunteer\ReleaseController;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to someone who signed up for a shadow spot ("Shadow this shift"), naming the regulars they'll shadow by first name and last initial.
 */
class ShadowSignedUpNotification extends Notification
{
    public string $area;

    public string $date;

    public string $when;

    /** @param  list<string>  $regularFirstNames */
    public function __construct(Shift $shift, public array $regularFirstNames)
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
        $this->when = $shift->whenText();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(EmailText::line('shadow_signed_up.subject', ['area' => $this->area, 'date' => $this->date]))
            ->greeting(EmailText::line('shadow_signed_up.greeting', ['first_name' => $notifiable->firstName()]))
            ->line(EmailText::line('shadow_signed_up.intro', ['area' => $this->area, 'when' => $this->when]));

        if ($this->regularFirstNames !== []) {
            $mail->line(ReleaseController::endSentence(EmailText::line('shadow_signed_up.regulars', ['names' => ReleaseController::names($this->regularFirstNames)])));
        }

        return $mail->line(EmailText::line('shadow_signed_up.outro'));
    }
}
