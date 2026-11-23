<?php

namespace Tests\Concerns;

use ArrayObject;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use RuntimeException;

/** For tests that check one failed email doesn't stop the rest: the first email sent throws, the way a mail server refusing an address would, and every email after it is recorded. */
trait FailsTheFirstEmail
{
    public const MAIL_FAILURE = 'The mail server refused it.';

    /**
     * Makes the first email sent from now on throw, and fakes exception reporting so the failure can be asserted.
     *
     * @return ArrayObject<int, array{0: int, 1: class-string}> Filled with [user id, notification class] for each email sent.
     */
    protected function failTheFirstEmail(): ArrayObject
    {
        Exceptions::fake();
        $sent = new ArrayObject;
        $failed = false;

        Event::listen(NotificationSending::class, function (NotificationSending $event) use (&$failed) {
            if (! $failed) {
                $failed = true;

                throw new RuntimeException(self::MAIL_FAILURE);
            }
        });

        Event::listen(NotificationSent::class, fn (NotificationSent $event) => $sent->append([$event->notifiable->id, $event->notification::class]));

        return $sent;
    }

    protected function assertTheFailureWasReported(): void
    {
        Exceptions::assertReported(fn (RuntimeException $exception) => $exception->getMessage() === self::MAIL_FAILURE);
    }
}
