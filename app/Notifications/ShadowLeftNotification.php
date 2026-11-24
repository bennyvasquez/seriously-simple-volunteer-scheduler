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
        $count = count($this->shifts);

        if ($count === 1) {
            $shift = $this->shifts[0];
            $mail = (new MailMessage)
                ->subject(EmailText::choice('shadow_left.subject', 1, ['shadow' => $name]))
                ->line(EmailText::choice('shadow_left.intro', 1, ['shadow' => $name, 'area' => $shift['area'], 'when' => $shift['when']]));

            return $this->withNote($mail)
                ->action(EmailText::line('shadow_left.action'), $shift['url'])
                ->line(EmailText::line('shadow_left.outro'));
        }

        $mail = (new MailMessage)
            ->subject(EmailText::choice('shadow_left.subject', $count, ['shadow' => $name, 'count' => $count]))
            ->line(EmailText::choice('shadow_left.intro', $count, ['shadow' => $name]));

        foreach ($this->shifts as $shift) {
            $mail->line(EmailText::line('shadow_left.shift_link', ['area' => $shift['area'], 'when' => $shift['when'], 'url' => $shift['url']]));
        }

        return $this->withNote($mail)->line(EmailText::line('shadow_left.outro'));
    }

    private function withNote(MailMessage $mail): MailMessage
    {
        return $this->note === null || $this->note === '' ? $mail : $mail->line(PlainText::line(EmailText::line('shadow_left.note', ['shadow' => $this->shadowFirstName, 'note' => $this->note])));
    }
}
