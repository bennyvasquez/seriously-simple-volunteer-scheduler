<?php

namespace App\Notifications;

use App\Models\Organization;
use App\Models\Shift;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * The urgent email (wireframe 49): a released shift starts in less than 24 hours and nobody has taken it. Sent to the area's active leads and everyone with the coordinator role (decision 12). `$reason` says why this person gets it: "a shift lead for Front desk" or "a coordinator".
 */
class CoverUrgentNotification extends Notification
{
    public string $area;

    public string $date;

    public string $when;

    public int $openPlaces;

    public string $releasedOn;

    public string $url;

    public function __construct(Shift $shift, public string $releaserFirstName, CarbonImmutable $releasedAt, public int $notifiedCount, public bool $resent, public string $reason)
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
        $this->when = $shift->whenText();
        $this->openPlaces = $shift->openPlaces();
        $this->releasedOn = $releasedAt->setTimezone(Organization::current()->timezone)->format('l, F j');
        $this->url = route('calendar.shift', $shift);
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('Needs someone tomorrow: '.$this->area.', '.$this->date)
            ->line('Nobody has taken this shift yet, and it starts in less than 24 hours.')
            ->line($this->area.', '.$this->when.'. Needs '.$this->openPlaces.' more '.Str::plural('person', $this->openPlaces).'.')
            ->line($this->releaserFirstName.' released it on '.$this->releasedOn.'. We emailed '.$this->notifiedCount.' '.Str::plural('volunteer', $this->notifiedCount).' when it was released'.($this->resent ? ' and again 72 hours before' : '').'.')
            ->action('Open the shift', $this->url)
            ->line('It\'s marked urgent on the calendar until someone takes it. From the shift you can put someone on it yourself.')
            ->line('You\'re getting this because you\'re '.$this->reason.'.');
    }
}
