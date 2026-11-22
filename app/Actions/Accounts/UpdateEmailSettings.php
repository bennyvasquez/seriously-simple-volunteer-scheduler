<?php

namespace App\Actions\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns the cover request emails on or off for a person (decision 5). Emails about their own shifts always go out. Saving what's already saved changes and logs nothing.
 */
class UpdateEmailSettings
{
    public function handle(User $user, bool $coverRequests): void
    {
        DB::transaction(function () use ($user, $coverRequests) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->cover_request_emails = $coverRequests;

            if (! $locked->isDirty('cover_request_emails')) {
                return;
            }

            // The old value comes back from the database as 1 or 0, so both sides are written as true or false.
            $changes = ['cover_request_emails' => ['old' => ! $coverRequests, 'new' => $coverRequests]];
            $locked->save();

            ChangeLogEntry::record($user, 'user.email_settings_changed', $locked, changes: $changes);
        });

        $user->cover_request_emails = $coverRequests;
        $user->syncOriginal();
    }
}
