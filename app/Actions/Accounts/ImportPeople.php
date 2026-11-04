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
 * Adds the people in a Hello Club export as "not invited yet". Only name, email and membership ID are kept, and nobody is emailed. People who already exist are left alone, except that someone matched by email with no membership ID yet gets it filled in.
 */
class ImportPeople
{
    /**
     * @param  Collection<int, ImportRow>  $rows
     */
    public function preview(Collection $rows, ?string $leadTag): ImportPreview
    {
        $emailLines = $rows->filter(fn (ImportRow $row) => $row->email !== '')->groupBy('email')->map->pluck('line');
        $memberLines = $rows->filter(fn (ImportRow $row) => $row->memberId !== '')->groupBy('memberId')->map->pluck('line');

        $add = new Collection;
        $existing = new Collection;
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

                continue;
            }

            $byEmail = User::query()->people()->where('email', $row->email)->first();

            if ($byEmail === null) {
                $add->push((object) ['row' => $row, 'makeLead' => $leadTag !== null && in_array($leadTag, $row->tags, true)]);
            } elseif ($byEmail->member_id === null) {
                $existing->push((object) ['row' => $row, 'user' => $byEmail, 'fillMemberId' => $row->memberId !== '']);
            } elseif ($byEmail->member_id === $row->memberId) {
                $existing->push((object) ['row' => $row, 'user' => $byEmail, 'fillMemberId' => false]);
            } else {
                $problems->push((object) ['row' => $row, 'reason' => 'Matches someone by email, but their membership ID is different']);
            }
        }

        return new ImportPreview($add, $existing, $problems);
    }

    /**
     * Creates the people in the preview and returns how many it created. All or nothing.
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

            return $created;
        });
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
