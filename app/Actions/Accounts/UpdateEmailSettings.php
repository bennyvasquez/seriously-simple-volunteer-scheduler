<?php

namespace App\Actions\Accounts;

use App\Models\ChangeLogEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Turns the cover request emails and the shift reminder emails on or off for a person (decision 5). Emails about their own shifts being changed always go out. Either or both can change in one save, and saving what's already saved changes and logs nothing.
 */
class UpdateEmailSettings
{
    public function handle(User $user, bool $coverRequests, bool $reminders): void
    {
        DB::transaction(function () use ($user, $coverRequests, $reminders) {
            $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $locked->cover_request_emails = $coverRequests;
            $locked->reminder_emails = $reminders;

            if (! $locked->isDirty(['cover_request_emails', 'reminder_emails'])) {
                return;
            }

            // The old values come back from the database as 1 or 0, so both sides are written as true or false.
            $changes = [];
            foreach (['cover_request_emails' => $coverRequests, 'reminder_emails' => $reminders] as $field => $new) {
                if ($locked->isDirty($field)) {
                    $changes[$field] = ['old' => ! $new, 'new' => $new];
                }
            }
            $locked->save();

            ChangeLogEntry::record($user, 'user.email_settings_changed', $locked, changes: $changes);
        });

        $user->cover_request_emails = $coverRequests;
        $user->reminder_emails = $reminders;
        $user->syncOriginal();
    }
}
