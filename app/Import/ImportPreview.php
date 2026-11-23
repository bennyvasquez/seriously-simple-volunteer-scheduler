<?php

namespace App\Import;

use App\Models\User;
use Illuminate\Support\Collection;

/**
 * What an import would do. Each item holds an ImportRow in `row`; nothing else from the file is kept.
 */
final readonly class ImportPreview
{
    /**
     * @param  Collection<int, object{row: ImportRow, makeLead: bool, clear: bool}>  $add
     * @param  Collection<int, object{row: ImportRow, user: User, fillMemberId: bool}>  $existing
     * @param  Collection<int, object{row: ImportRow, reason: string}>  $problems
     * @param  Collection<int, object{row: ImportRow, user: User}>  $clearExisting  People already here who have the cleared tag and aren't cleared yet. Never someone turned off or moved back to shadowing.
     * @param  Collection<int, object{row: ImportRow, user: User}>  $movedBack  People already here who have the cleared tag but a coordinator moved back to shadowing, so the import leaves them alone.
     */
    public function __construct(
        public Collection $add,
        public Collection $existing,
        public Collection $problems,
        public Collection $clearExisting = new Collection,
        public Collection $movedBack = new Collection,
    ) {}

    /**
     * The people already here that the import changes nothing about, apart from perhaps adding a membership ID: `existing` without the ones being cleared or listed as moved back.
     *
     * @return Collection<int, object{row: ImportRow, user: User, fillMemberId: bool}>
     */
    public function leftAlone(): Collection
    {
        $listedElsewhere = $this->clearExisting->concat($this->movedBack)->map(fn ($item) => $item->user->id)->all();

        return $this->existing->reject(fn ($item) => in_array($item->user->id, $listedElsewhere, true))->values();
    }
}
