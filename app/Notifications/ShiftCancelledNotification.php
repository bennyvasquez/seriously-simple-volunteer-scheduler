<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/** Sent to everyone on shifts that were cancelled. Takes a collection so a closure that cancels several shifts sends one email; a single cancelled date passes a one-item collection. */
class ShiftCancelledNotification extends Notification
{
    /** @var list<string> One "Front desk shift on Thursday, October 22, 6:30 to 9:30pm" per shift. */
    public array $shifts;

    /** @param  Collection<int, Shift>  $shifts */
    public function __construct(Collection $shifts, public string $reason)
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => $shift->area->name.' shift on '.$shift->whenText())->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        if (count($this->shifts) === 1) {
            return (new MailMessage)
                ->subject('A shift you were on is cancelled')
                ->line("The {$this->shifts[0]} is cancelled: {$this->sentence()} You don't need to do anything.");
        }

        $mail = (new MailMessage)
            ->subject('Shifts you were on are cancelled')
            ->line("These shifts are cancelled: {$this->sentence()}");

        foreach ($this->shifts as $shift) {
            $mail->line("The {$shift}.");
        }

        return $mail->line("You don't need to do anything.");
    }

    /** The reason as a sentence: a period is added unless it already ends with one, or with a question or exclamation mark. */
    private function sentence(): string
    {
        $reason = trim($this->reason);

        return preg_match('/[.!?]$/', $reason) === 1 ? $reason : $reason.'.';
    }
}
