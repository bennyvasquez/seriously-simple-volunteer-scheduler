<?php

namespace App\Actions\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ends every session this person has, on every device (or every one except `$exceptSessionId`, to keep the device they are using), and makes old remember-me cookies stop working (spec section 13, "Sessions").
 */
class SignOutEverywhere
{
    public function handle(User $user, ?string $exceptSessionId = null, ?User $actor = null): void
    {
        DB::table('sessions')
            ->where('user_id', $user->id)
            ->when($exceptSessionId !== null, fn ($query) => $query->where('id', '!=', $exceptSessionId))
            ->delete();
        $user->forceFill(['remember_token' => Str::random(60)])->save();

        ChangeLogEntry::record($actor ?? $user, 'user.signed_out_everywhere', $user, ['kept_current_session' => $exceptSessionId !== null]);
    }
}
