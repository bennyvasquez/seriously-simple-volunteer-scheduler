<?php

namespace App\Enums;

enum LoginLinkPurpose: string
{
    case Invitation = 'invitation';
    case SignIn = 'sign_in';
    /** Emailed to the current address of someone without a password, so they can confirm it's them before changing their address. */
    case ConfirmIdentity = 'confirm_identity';
    /** Emailed to a new address; opening it makes the change. */
    case EmailChange = 'email_change';

    public function lifetimeInMinutes(): int
    {
        return match ($this) {
            self::Invitation => 7 * 24 * 60,
            self::SignIn, self::ConfirmIdentity => 15,
            self::EmailChange => 60,
        };
    }

    /** The kinds of link the login link page accepts. The others only work on their own pages. */
    public static function forLoggingIn(): array
    {
        return [self::Invitation, self::SignIn];
    }
}
