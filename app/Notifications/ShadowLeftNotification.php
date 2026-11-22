<?php

namespace App\Notifications;

use App\Models\Shift;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

/**
 * Tells everyone with the coordinator role that a shadow can't make their shadow shift (decision 14), so they know the trainee won't be there, with the note the shadow left, if any. One email per action, listing every shift when a range was released.
 */
class ShadowLeftNotification extends Notification
{
    /** @var list<array{area: string, when: string, url: string}> */
    public array $shifts;

    /** @param  Collection<int, Shift>  $shifts  With their areas loaded. */
    public function __construct(public string $shadowFirstName, Collection $shifts, public ?string $note = null)
    {
        $this->shifts = $shifts->map(fn (Shift $shift) => [
            'area' => $shift->area->name,
            'when' => $shift->whenText(),
            'url' => route('calendar.shift', $shift),
        ])->values()->all();
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $name = $this->shadowFirstName;

        if (count($this->shifts) === 1) {
            $shift = $this->shifts[0];
            $mail = (new MailMessage)
                ->subject($name.' can\'t make their shadow shift')
                ->line($name.' can\'t make their shadow shift on '.$shift['area'].', '.$shift['when'].'. We\'ve taken them off it, so they won\'t be there and the shadow spot is open again.');

            return $this->withNote($mail)
                ->action('Open the shift', $shift['url'])
                ->line('You\'re getting this because you\'re a coordinator.');
        }

        $mail = (new MailMessage)
            ->subject($name.' can\'t make '.count($this->shifts).' shadow shifts')
            ->line($name.' can\'t make these shadow shifts. We\'ve taken them off, so they won\'t be there and the shadow spots are open again:');

        foreach ($this->shifts as $shift) {
            $mail->line('['.$shift['area'].', '.$shift['when'].']('.$shift['url'].')');
        }

        return $this->withNote($mail)->line('You\'re getting this because you\'re a coordinator.');
    }

    private function withNote(MailMessage $mail): MailMessage
    {
        return $this->note === null || $this->note === '' ? $mail : $mail->line(PlainText::line('Note from '.$this->shadowFirstName.': '.$this->note));
    }
}
