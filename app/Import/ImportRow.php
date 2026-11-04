<?php

namespace App\Import;

/**
 * One person from a Hello Club export. Only these fields are ever read from the file; tags are the Categories cell, kept to decide on a role and never stored.
 */
final readonly class ImportRow
{
    /**
     * @param  string  $email  Lowercased and trimmed; empty when the export has none.
     * @param  array<int, string>  $tags
     * @param  int  $line  The record's position in the file, counting the header as 1, for messages.
     */
    public function __construct(
        public string $name,
        public string $email,
        public string $memberId,
        public array $tags,
        public int $line,
    ) {}
}
