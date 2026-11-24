<?php

namespace App\Notifications;

use App\Enums\ShadowSpotNotice;
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
        $count = count($this->shifts);
        $vars = ['count' => $count, 'date' => $this->date, 'area' => $this->area, 'when' => $this->when, 'releaser' => $this->releaserFirstName];

        if ($this->notice === ShadowSpotNotice::OnHold) {
            $mail = (new MailMessage)
                ->subject(EmailText::choice('shadow_spot.on_hold.subject', $count, $vars))
                ->line(EmailText::choice('shadow_spot.on_hold.intro', $count, $vars));

            if ($count > 1) {
                foreach ($this->shifts as $shift) {
                    $mail->line($shift);
                }
            }

            return $mail->line(EmailText::choice('shadow_spot.on_hold.outro', $count));
        }

        if ($this->notice === ShadowSpotNotice::BackOn) {
            $vars['taker'] = (string) $this->takerFirstName;
            $mail = (new MailMessage)
                ->subject(EmailText::choice('shadow_spot.back_on.subject', $count, $vars))
                ->line(EmailText::choice('shadow_spot.back_on.intro', $count, $vars));

            if ($count > 1) {
                foreach ($this->shifts as $shift) {
                    $mail->line($shift);
                }
            }

            return $mail;
        }

        $mail = (new MailMessage)
            ->subject(EmailText::choice('shadow_spot.still_uncovered.subject', $count))
            ->line(EmailText::choice('shadow_spot.still_uncovered.intro', $count, $vars));

        // One shift names its lead if it has one. Several shifts name each lead once.
        $leads = $count > 1 ? $this->leadTexts : ($this->leadText === null ? [] : [$this->leadText]);

        if ($count > 1) {
            foreach ($this->shifts as $shift) {
                $mail->line($shift);
            }
        }

        $know = $leads === [] ? '' : ' '.EmailText::choice('shadow_spot.still_uncovered.knows', count($leads), ['leads' => Arr::join($leads, ', ', ' and ')]);

        return $mail->line(EmailText::line('shadow_spot.still_uncovered.reach_out').$know);
    }
}
