<?php

namespace App\Import;

use App\Support\EmailAddress;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Reads a Hello Club members export. The export holds birth dates, addresses, emergency contacts and notes, so this reads only the columns SSVS needs and keeps nothing else beyond the line it's on.
 */
class HelloClubExport
{
    private const REQUIRED = ['Full name', 'Email', 'Profile ID'];

    /** @return Collection<int, ImportRow> */
    public static function read(string $path): Collection
    {
        $handle = is_file($path) ? @fopen($path, 'rb') : false;

        if ($handle === false) {
            throw new InvalidArgumentException("Couldn't open {$path}.");
        }

        try {
            $header = fgetcsv($handle, 0, ',', '"', '');

            if ($header === false || $header === [null]) {
                throw new InvalidArgumentException('The file is empty.');
            }

            $header[0] = Str::chopStart((string) $header[0], "\u{FEFF}");
            $columns = [];

            foreach ($header as $index => $title) {
                $columns[trim((string) $title)] ??= $index;
            }

            foreach (self::REQUIRED as $title) {
                if (! isset($columns[$title])) {
                    throw new InvalidArgumentException("The file has no {$title} column. Is it a Hello Club members export?");
                }
            }

            $rows = new Collection;
            $line = 1;

            while (($record = fgetcsv($handle, 0, ',', '"', '')) !== false) {
                $line++;

                if ($record === [null]) {
                    continue;
                }

                $categories = isset($columns['Categories']) ? (string) ($record[$columns['Categories']] ?? '') : '';
                $tags = array_values(array_filter(array_map('trim', explode(',', $categories)), fn (string $tag) => $tag !== ''));

                $rows->push(new ImportRow(
                    name: trim((string) ($record[$columns['Full name']] ?? '')),
                    email: EmailAddress::normalize((string) ($record[$columns['Email']] ?? '')),
                    memberId: trim((string) ($record[$columns['Profile ID']] ?? '')),
                    tags: $tags,
                    line: $line,
                ));
            }

            return $rows;
        } finally {
            fclose($handle);
        }
    }
}
