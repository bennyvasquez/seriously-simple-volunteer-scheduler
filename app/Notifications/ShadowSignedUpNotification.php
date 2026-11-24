<?php

namespace App\Notifications;

use App\Http\Controllers\Volunteer\ReleaseController;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Sent to someone who signed up for a shadow spot ("Shadow this shift"), naming the regulars they'll shadow by first name.
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
            ->subject('You\'re shadowing '.$this->area.' on '.$this->date)
            ->greeting('Hi '.$notifiable->firstName().',')
            ->line('You\'re signed up to shadow '.$this->area.' on '.$this->when.'.');

        if ($this->regularFirstNames !== []) {
            $mail->line(ReleaseController::endSentence('You\'ll shadow '.ReleaseController::names($this->regularFirstNames)));
        }

        return $mail->line('If you can\'t make it, press Can\'t make it on Home so a coordinator knows.');
    }
}
