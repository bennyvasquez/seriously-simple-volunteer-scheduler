<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Auth\Recaller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/** Whether the device making a request is one this person asked to remember. */
final class RememberedDevice
{
    /** True when the guard signed in by remember-me, or when the request carries a remember-me cookie that names this person. A leftover cookie for someone else, or one that can't be read, isn't theirs. */
    public static function belongsTo(Request $request, User $user): bool
    {
        $guard = Auth::guard('web');

        if ($guard->viaRemember()) {
            return true;
        }

        $cookie = $request->cookies->get($guard->getRecallerName());

        if (! is_string($cookie) || $cookie === '') {
            return false;
        }

        $recaller = new Recaller($cookie);

        return $recaller->valid() && (string) $recaller->id() === (string) $user->getKey();
    }
}
