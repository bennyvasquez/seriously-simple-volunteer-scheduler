<?php

namespace App\Actions;

use App\Actions\Scheduling\GenerateShifts;
use App\Models\ChangeLogEntry;
use App\Models\Organization;
use App\Models\User;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Changes the organization's name, time zone, how many weeks ahead shifts are made and how many hours before a shift its reminder goes out (2, 12, 24 or 48, or null for off). Admins only. A new time zone doesn't move or regenerate shifts already made. Raising weeks ahead makes the extra shifts right away, after the change is saved; lowering it leaves existing shifts alone. Logs organization.updated.
 */
class UpdateSettings
{
    public function __construct(private GenerateShifts $generateShifts) {}

    public function handle(User $actor, string $name, string $timezone, int $weeksAhead, ?int $reminderHours): Organization
    {
        Gate::forUser($actor)->authorize('manage-settings');

        $name = trim($name);
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Give the organization a name.';
        } elseif (mb_strlen($name) > 150) {
            $errors['name'] = 'Keep the name to 150 characters or fewer.';
        }

        if (! in_array($timezone, DateTimeZone::listIdentifiers(), true)) {
            $errors['timezone'] = 'Pick a time zone from the list.';
        }

        if ($weeksAhead < 1 || $weeksAhead > 26) {
            $errors['weeks_ahead'] = 'Choose a number of weeks from 1 to 26.';
        }

        if ($reminderHours !== null && ! in_array($reminderHours, Organization::REMINDER_CHOICES, true)) {
            $errors['reminder_hours'] = 'Choose when reminders go out from the list.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        [$organization, $raised] = DB::transaction(function () use ($actor, $name, $timezone, $weeksAhead, $reminderHours) {
            $organization = Organization::query()->lockForUpdate()->firstOrFail();
            $before = $organization->weeks_ahead;

            $organization->fill(['name' => $name, 'timezone' => $timezone, 'weeks_ahead' => $weeksAhead, 'reminder_hours' => $reminderHours]);
            $changes = ChangeLogEntry::changesOf($organization);

            if ($changes !== []) {
                $organization->save();
                ChangeLogEntry::record($actor, 'organization.updated', $organization, [], $changes);
            }

            return [$organization, $weeksAhead > $before];
        });

        // After the commit: generation takes the area locks, which come before anything else in the lock order.
        if ($raised) {
            $this->generateShifts->handle($organization);
        }

        return $organization;
    }
}
