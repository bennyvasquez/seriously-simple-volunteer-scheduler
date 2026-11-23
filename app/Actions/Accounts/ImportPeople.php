<?php

namespace App\Actions\Accounts;

use App\Enums\Role;
use App\Import\ImportPreview;
use App\Import\ImportRow;
use App\Models\Area;
use App\Models\ChangeLogEntry;
use App\Models\User;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Adds the people in a Hello Club export as "not invited yet". Only name, email and membership ID are kept, and nobody is emailed. People who already exist are left alone, except that someone matched by email with no membership ID yet gets it filled in, and, with a cleared tag, someone with the tag who isn't cleared gets cleared. The tag never clears someone turned off or someone a coordinator moved back to shadowing.
 */
class ImportPeople
{
    /**
     * @param  Collection<int, ImportRow>  $rows
     */
    public function preview(Collection $rows, ?string $leadTag, ?string $clearedTag = null): ImportPreview
    {
        $emailLines = $rows->filter(fn (ImportRow $row) => $row->email !== '')->groupBy('email')->map->pluck('line');
        $memberLines = $rows->filter(fn (ImportRow $row) => $row->memberId !== '')->groupBy('memberId')->map->pluck('line');

        $add = new Collection;
        $existing = new Collection;
        $clearExisting = new Collection;
        $movedBack = new Collection;
        $problems = new Collection;

        foreach ($rows as $row) {
            $problem = $this->problemWith($row, $emailLines, $memberLines);

            if ($problem !== null) {
                $problems->push((object) ['row' => $row, 'reason' => $problem]);

                continue;
            }

            $byMemberId = $row->memberId !== '' ? User::query()->people()->where('member_id', $row->memberId)->first() : null;

            if ($byMemberId !== null) {
                $existing->push((object) ['row' => $row, 'user' => $byMemberId, 'fillMemberId' => false]);
                $this->noteClearing($clearExisting, $movedBack, $row, $byMemberId, $clearedTag);

                continue;
            }

            $byEmail = User::query()->people()->where('email', $row->email)->first();

            if ($byEmail === null) {
                $add->push((object) ['row' => $row, 'makeLead' => $this->hasTag($row, $leadTag), 'clear' => $this->hasTag($row, $clearedTag)]);
            } elseif ($byEmail->member_id === null) {
                $existing->push((object) ['row' => $row, 'user' => $byEmail, 'fillMemberId' => $row->memberId !== '']);
                $this->noteClearing($clearExisting, $movedBack, $row, $byEmail, $clearedTag);
            } elseif ($byEmail->member_id === $row->memberId) {
                $existing->push((object) ['row' => $row, 'user' => $byEmail, 'fillMemberId' => false]);
                $this->noteClearing($clearExisting, $movedBack, $row, $byEmail, $clearedTag);
            } else {
                $problems->push((object) ['row' => $row, 'reason' => 'Matches someone by email, but their membership ID is different']);
            }
        }

        return new ImportPreview($add, $existing, $problems, $clearExisting, $movedBack);
    }

    /**
     * Creates the people in the preview, clears the ones the cleared tag matched, and returns how many it created. All or nothing.
     */
    public function apply(User $admin, ImportPreview $preview, ?Area $leadArea): int
    {
        if (! $admin->isActive() || ! $admin->hasRole(Role::Admin)) {
            throw new AuthorizationException('Only admins can import people.');
        }

        return DB::transaction(function () use ($admin, $preview, $leadArea) {
            $created = 0;

            foreach ($preview->add as $item) {
                $memberId = $item->row->memberId !== '' ? $item->row->memberId : null;

                try {
                    // Someone may have taken this membership ID since the preview, and CreatePerson only checks the email address.
                    if ($memberId !== null && User::query()->where('member_id', $memberId)->exists()) {
                        throw new DomainException('Someone already has that membership ID.');
                    }

                    $person = app(CreatePerson::class)->handle($admin, $item->row->name, $item->row->email, $memberId);
                    $created++;

                    if ($item->makeLead) {
                        app(GrantRole::class)->handle($admin, $person, Role::AreaLead, $leadArea);
                    }

                    if ($item->clear) {
                        $locked = User::query()->whereKey($person->id)->lockForUpdate()->firstOrFail();
                        app(ClearPerson::class)->clearLocked($admin, $locked, 'import');
                    }
                } catch (DomainException $exception) {
                    throw new DomainException("Line {$item->row->line}: {$exception->getMessage()} Nothing was saved.", previous: $exception);
                }
            }

            foreach ($preview->existing->where('fillMemberId', true) as $item) {
                $person = User::query()->whereKey($item->user->id)->lockForUpdate()->first();

                if ($person === null || $person->member_id !== null || User::query()->where('member_id', $item->row->memberId)->exists()) {
                    continue;
                }

                $person->forceFill(['member_id' => $item->row->memberId]);
                $changes = ChangeLogEntry::changesOf($person);
                $person->save();
                ChangeLogEntry::record($admin, 'user.member_id_added', $person, changes: $changes);
            }

            foreach ($preview->clearExisting as $item) {
                $person = User::query()->whereKey($item->user->id)->lockForUpdate()->first();

                // Checked again under the lock, in case they were turned off or moved back since the preview.
                if ($person !== null && $person->isActive() && $person->shadowing_since === null) {
                    app(ClearPerson::class)->clearLocked($admin, $person, 'import');
                }
            }

            return $created;
        });
    }

    /**
     * The one comparison for a category option: the tag must equal one of the row's tags exactly (the reader already trims them).
     */
    private function hasTag(ImportRow $row, ?string $tag): bool
    {
        return $tag !== null && in_array($tag, $row->tags, true);
    }

    /**
     * Notes someone already here who has the cleared tag and isn't cleared. A coordinator's move back to shadowing wins over the tag, so those people go in `$movedBack` and stay as they are. Someone turned off isn't cleared either; they stay in the "left alone" group.
     *
     * @param  Collection<int, object>  $clearExisting
     * @param  Collection<int, object>  $movedBack
     */
    private function noteClearing(Collection $clearExisting, Collection $movedBack, ImportRow $row, User $user, ?string $clearedTag): void
    {
        if ($user->isCleared() || ! $this->hasTag($row, $clearedTag) || ! $user->isActive()) {
            return;
        }

        if ($user->shadowing_since !== null) {
            $movedBack->push((object) ['row' => $row, 'user' => $user]);
        } else {
            $clearExisting->push((object) ['row' => $row, 'user' => $user]);
        }
    }

    private function problemWith(ImportRow $row, Collection $emailLines, Collection $memberLines): ?string
    {
        if ($row->name === '') {
            return 'No name';
        }

        if (mb_strlen($row->name) > 255) {
            return 'The name is too long';
        }

        if (mb_strlen($row->memberId) > 64) {
            return 'The membership ID is too long';
        }

        if ($row->email === '') {
            return 'No email address';
        }

        if (Validator::make(['email' => $row->email], ['email' => ['email', 'max:255']])->fails()) {
            return 'The email address isn\'t valid';
        }

        if (User::isUnusableEmail($row->email)) {
            return 'That email address can\'t be used';
        }

        foreach ([$emailLines[$row->email] ?? collect(), $memberLines[$row->memberId] ?? collect()] as $lines) {
            if ($lines->count() > 1) {
                return 'Appears twice in the file, on lines '.$lines->take(2)->implode(' and ');
            }
        }

        return null;
    }
}
