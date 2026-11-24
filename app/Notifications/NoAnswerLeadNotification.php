<?php

namespace App\Notifications;

use App\Calendar\TimeFormat;
use App\Models\Organization;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\HtmlString;

/**
 * The 48-hour heads-up (screen 59), sent once per shift to the area's leads (or the coordinators when it has none): the time of the shift changed and these people haven't said whether the new time works. `$names` are short names, `$was` is the old time, `$reason` is why this person gets it ("a shift lead for Front desk" or "a coordinator").
 */
class NoAnswerLeadNotification extends Notification
{
    public string $subject;

    public string $date;

    public string|HtmlString $line;

    public string $url;

    /** @param  list<string>  $names */
    public function __construct(Shift $shift, public array $names, string $was, public string $reason)
    {
        $timezone = Organization::current()->timezone;
        $start = $shift->starts_at->setTimezone($timezone);
        $this->date = $start->format('l, F j');
        $this->subject = EmailText::line('no_answer_lead.subject', ['area' => $shift->area->name, 'date' => $this->date]);
        $this->line = EmailText::line('no_answer_lead.shift_line', ['area' => $shift->area->name, 'now' => TimeFormat::range($start, $shift->ends_at->setTimezone($timezone)), 'was' => $was]);
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
            ->greeting(EmailText::line('no_answer_lead.greeting', ['first_name' => $notifiable->firstName()]))
            ->line(EmailText::line('no_answer_lead.intro'))
            ->line(EmailText::line('no_answer_lead.shift', ['date' => $this->date, 'shift_line' => $this->line, 'names' => implode(', ', $this->names)]))
            ->line(EmailText::line('no_answer_lead.outro'))
            ->action(EmailText::line('no_answer_lead.action'), $this->url)
            ->line(EmailText::line('no_answer_lead.only_reminder'))
            ->line(EmailText::line('no_answer_lead.reason', ['reason' => $this->reason]));
    }
}
