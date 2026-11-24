<?php

namespace App\Notifications;

use App\Enums\ShadowSpotNotice;
use App\Http\Controllers\Volunteer\ReleaseController;
use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Arr;

/**
 * Tells a shadow what's happening to their spot when the people they'd shadow change (decision 6): on hold, back on, or still uncovered the day before. `$takerFirstName` is for BackOn, and `$leadText` ("Alex (Shift lead)") for StillUncovered, when the area has a lead. When one action puts several of the shadow's shifts on hold, escalates several of them, or brings several back on (someone taking a slot), merge() folds them into one email listing them all.
 */
class ShadowSpotNotification extends Notification
{
    public string $area;

    public string $date;

    public string $when;

    /** @var list<string> "Front desk, Thursday, October 22, 6:30 to 9:30pm" for each shift, the first one included. */
    public array $shifts;

    /** @var list<string> The lead texts of every shift, without repeats. */
    public array $leadTexts;

    public function __construct(Shift $shift, public ShadowSpotNotice $notice, public string $releaserFirstName, public ?string $takerFirstName = null, public ?string $leadText = null)
    {
        $this->area = $shift->area->name;
        $this->date = $shift->local_date->format('l, F j');
        $this->when = $shift->whenText();
        $this->shifts = [$this->area.', '.$this->when];
        $this->leadTexts = $leadText === null ? [] : [$leadText];
    }

    /** Adds another shift's email of the same kind to this one, so the shadow gets one email listing both. */
    public function merge(self $other): self
    {
        $this->shifts = array_merge($this->shifts, $other->shifts);
        $this->leadTexts = array_values(array_unique(array_merge($this->leadTexts, $other->leadTexts)));

        return $this;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $area = $this->area;

        if ($this->notice === ShadowSpotNotice::OnHold && count($this->shifts) > 1) {
            $mail = (new MailMessage)
                ->subject('Your shadow shifts on '.count($this->shifts).' dates are on hold')
                ->line($this->releaserFirstName.' can\'t make these shifts, so right now there\'s nobody for you to shadow on them:');

            foreach ($this->shifts as $shift) {
                $mail->line($shift);
            }

            return $mail->line('We\'ve asked other volunteers to cover them, and we\'ll email you as soon as someone does. You don\'t need to do anything.');
        }

        if ($this->notice === ShadowSpotNotice::BackOn && count($this->shifts) > 1) {
            $mail = (new MailMessage)
                ->subject('Your shadow shifts on '.count($this->shifts).' dates are back on')
                ->line($this->takerFirstName.' is covering, so you\'ll shadow '.$this->takerFirstName.' on these shifts:');

            foreach ($this->shifts as $shift) {
                $mail->line($shift);
            }

            return $mail;
        }

        if ($this->notice === ShadowSpotNotice::StillUncovered && count($this->shifts) > 1) {
            $mail = (new MailMessage)
                ->subject('Your shadow shifts tomorrow still have nobody to shadow')
                ->line('Nobody has taken these shifts yet. If someone does, we\'ll email you.');

            foreach ($this->shifts as $shift) {
                $mail->line($shift);
            }

            $know = $this->leadTexts === [] ? '' : ' '.Arr::join($this->leadTexts, ', ', ' and ').(count($this->leadTexts) === 1 ? ' knows.' : ' know.');

            return $mail->line('If nobody does, there\'s nobody to shadow, so please reach out to the volunteer coordinators to confirm next steps.'.$know);
        }

        return match ($this->notice) {
            ShadowSpotNotice::OnHold => (new MailMessage)
                ->subject('Your shadow shift on '.$this->date.' is on hold')
                ->line($this->releaserFirstName.' can\'t make their '.$area.' shift on '.$this->date.', so right now there\'s nobody for you to shadow.')
                ->line('We\'ve asked other volunteers to cover it, and we\'ll email you as soon as someone does. You don\'t need to do anything.'),
            ShadowSpotNotice::BackOn => (new MailMessage)
                ->subject('Your shadow shift on '.$this->date.' is back on')
                ->line($this->takerFirstName.' is covering, so you\'ll shadow '.ReleaseController::endSentence($this->takerFirstName).' '.$this->area.', '.$this->when.'.'),
            ShadowSpotNotice::StillUncovered => (new MailMessage)
                ->subject('Your shadow shift tomorrow still has nobody to shadow')
                ->line('Nobody has taken '.$this->releaserFirstName.'\'s '.$area.' shift on '.$this->date.' yet. If someone does, we\'ll email you.')
                ->line('If nobody does, there\'s nobody to shadow, so please reach out to the volunteer coordinators to confirm next steps.'.($this->leadText !== null ? ' '.$this->leadText.' knows.' : '')),
        };
    }
}
