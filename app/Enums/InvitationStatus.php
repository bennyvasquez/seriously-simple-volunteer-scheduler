<?php

namespace App\Enums;

/** Where a person is in being invited. Worked out from the person's invited_at, email_verified_at and invitation links, never stored. */
enum InvitationStatus: string
{
    case NotInvited = 'not_invited';
    case Waiting = 'waiting';
    case Accepted = 'accepted';
    case RanOut = 'ran_out';

    public function label(): string
    {
        return match ($this) {
            self::NotInvited => 'Not invited yet',
            self::Waiting => 'Waiting',
            self::Accepted => 'Accepted',
            self::RanOut => 'Ran out',
        };
    }
}
