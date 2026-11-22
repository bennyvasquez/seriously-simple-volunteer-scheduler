<?php

namespace App\Notifications;

use App\Enums\ShadowSpotNotice;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Tells a shadow what's happening to their spot when the people they'd shadow change (decision 6): on hold, back on, or still uncovered the day before. `$takerFirstName` is for BackOn, and `$leadText` ("Alex (Shift lead)") for StillUncovered, when the area has a lead. `$alsoOnHold` is for OnHold when one release puts several of the shadow's shifts on hold, so they get one email listing them all.
 */
class ShadowSpotNotification extends Notification
{
    public string $area;

    public string $date;

    public string $when;

    /** @var list<string> "Front desk, Thursday, October 22, 6:30 to 9:30pm" for each shift on hold, the first one included. */
    public array $onHold;

    /** @param  Collection<int, Shift>|null  $alsoOnHold  With their areas loaded. */
    public function __construct(Shift $shift, public ShadowSpotNotice $notice, public string $releaserFirstName, public ?string $takerFirstName = null, public ?string $leadText = null, ?Collection $alsoOnHold = null)
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
        $this->when = $shift->whenText();
        $this->onHold = collect([$shift])->concat($alsoOnHold ?? [])->map(fn (Shift $held) => $held->area->name.', '.$held->whenText())->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $area = $this->area;

        if ($this->notice === ShadowSpotNotice::OnHold && count($this->onHold) > 1) {
            $mail = (new MailMessage)
                ->subject('Your shadow shifts on '.count($this->onHold).' dates are on hold')
                ->line($this->releaserFirstName.' can\'t make these shifts, so right now there\'s nobody for you to shadow on them:');

            foreach ($this->onHold as $shift) {
                $mail->line($shift);
            }

            return $mail->line('We\'ve asked other volunteers to cover them, and we\'ll email you as soon as someone does. You don\'t need to do anything.');
        }

        return match ($this->notice) {
            ShadowSpotNotice::OnHold => (new MailMessage)
                ->subject('Your shadow shift on '.$this->date.' is on hold')
                ->line($this->releaserFirstName.' can\'t make their '.$area.' shift on '.$this->date.', so right now there\'s nobody for you to shadow.')
                ->line('We\'ve asked other volunteers to cover it, and we\'ll email you as soon as someone does. You don\'t need to do anything.'),
            ShadowSpotNotice::BackOn => (new MailMessage)
                ->subject('Your shadow shift on '.$this->date.' is back on')
                ->line($this->takerFirstName.' is covering, so you\'ll shadow '.$this->takerFirstName.'. '.$this->area.', '.$this->when.'.'),
            ShadowSpotNotice::StillUncovered => (new MailMessage)
                ->subject('Your shadow shift tomorrow still has nobody to shadow')
                ->line('Nobody has taken '.$this->releaserFirstName.'\'s '.$area.' shift on '.$this->date.' yet. If someone does, we\'ll email you.')
                ->line('If nobody does, there\'s nobody to shadow, so please don\'t come in for it.'.($this->leadText !== null ? ' '.$this->leadText.' knows.' : '')),
        };
    }
}
