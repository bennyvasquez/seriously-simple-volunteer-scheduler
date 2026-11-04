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
     * @param  Collection<int, object{row: ImportRow, makeLead: bool}>  $add
     * @param  Collection<int, object{row: ImportRow, user: User, fillMemberId: bool}>  $existing
     * @param  Collection<int, object{row: ImportRow, reason: string}>  $problems
     */
    public function __construct(
        public Collection $add,
        public Collection $existing,
        public Collection $problems,
    ) {}
}
