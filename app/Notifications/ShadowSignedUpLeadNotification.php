<?php

namespace App\Notifications;

use App\Http\Controllers\Volunteer\ReleaseController;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Tells an area's active leads that someone signed up to shadow one of its shifts: "{First name and last initial} is shadowing {Area}, {date}", with who's on that shift.
 */
class ShadowSignedUpLeadNotification extends Notification
{
    public string $area;

    public string $date;

    public string $when;

    public string $url;

    /** @param  list<string>  $regularFirstNames */
    public function __construct(Shift $shift, public string $shadowFirstName, public array $regularFirstNames)
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
        $mail = (new MailMessage)
            ->subject($this->shadowFirstName.' is shadowing '.$this->area.', '.$this->date)
            ->line($this->shadowFirstName.' signed up to shadow '.$this->area.' on '.$this->when.'.');

        if ($this->regularFirstNames !== []) {
            $mail->line(ReleaseController::names($this->regularFirstNames).(count($this->regularFirstNames) === 1 ? ' is' : ' are').' on that shift.');
        }

        return $mail->action('Open the shift', $this->url);
    }
}
