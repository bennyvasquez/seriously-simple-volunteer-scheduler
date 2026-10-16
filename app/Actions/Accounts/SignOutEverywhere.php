<?php

namespace App\Actions\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends every session this person has, on every device, and makes old remember-me cookies stop working (spec section 13, "Sessions").
 */
class SignOutEverywhere
{
    public function handle(User $user): void
    {
        DB::table('sessions')->where('user_id', $user->id)->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        ChangeLogEntry::record($user, 'user.signed_out_everywhere', $user);
    }
}
