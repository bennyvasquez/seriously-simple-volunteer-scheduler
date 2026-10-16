<?php

namespace App\Enums;

enum LoginLinkPurpose: string
{
    case Invitation = 'invitation';
    case SignIn = 'sign_in';

    public function lifetimeInMinutes(): int
    {
        return match ($this) {
            self::Invitation => 7 * 24 * 60,
            self::SignIn => 15,
        };
    }
}
