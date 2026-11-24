<?php

namespace App\Notifications;

use App\Calendar\TimeFormat;
use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * The 48-hour heads-up (screen 59), sent once per shift to the area's leads (or the coordinators when it has none): the time of the shift changed and these people haven't said whether the new time works. `$names` are short names, `$was` is the old time, `$reason` is why this person gets it ("a shift lead for Front desk" or "a coordinator").
 */
class NoAnswerLeadNotification extends Notification
{
    public string $subject;

    public string $date;

    public string $line;

    public string $url;

    /** @param  list<string>  $names */
    public function __construct(Shift $shift, public array $names, string $was, public string $reason)
    {
        $timezone = Organization::current()->timezone;
        $start = $shift->starts_at->setTimezone($timezone);
        $this->date = $start->format('l, F j');
        $this->subject = 'No answer yet: '.$shift->area->name.', '.$this->date;
        $this->line = $shift->area->name.', '.TimeFormat::range($start, $shift->ends_at->setTimezone($timezone)).' (was '.$was.')';
        $this->url = route('calendar.shift', $shift);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->subject)
            ->greeting('Hi '.$notifiable->firstName().',')
            ->line('The time of this shift changed, and these people haven\'t said whether the new time works:')
            ->line('**'.$this->date.'**  '."\n".$this->line.'  '."\n".implode(', ', $this->names))
            ->line('It starts in less than 48 hours. You might check with them that they know.')
            ->action('Open the shift', $this->url)
            ->line('This is the only reminder. Nobody else is emailed.')
            ->line('You\'re getting this because you\'re '.$this->reason.'.');
    }
}
