<?php

namespace App\Emails;

/**
 * One email as the editor screens describe it (see EmailCatalog). `lines` are in the order they read in the email: each has the line's full key (such as `shift_reminder.opening`), a short label for a coordinator, and a sentence saying when it shows (null when it always does). `examples` give every variable a value for the preview. `versions` are the ways the email can read, such as "Someone on the shift" and "A shadow": each names the line keys it shows and any example values that differ.
 */
final readonly class CatalogEmail
{
    /**
     * @param  string  $key  The key in lang/en/emails.php, or `every_email`.
     * @param  string  $when  One plain sentence saying when the email is sent and to whom.
     * @param  list<array{key: string, label: string, when: ?string}>  $lines
     * @param  array<string, string>  $examples  Variable name to example value. A link variable's value is the visible link text.
     * @param  array<string, array{lines: list<string>, examples: array<string, string>}>  $versions
     */
    public function __construct(
        public string $key,
        public string $title,
        public string $group,
        public string $when,
        public array $lines,
        public array $examples,
        public array $versions,
    ) {}

    /** @return list<string> */
    public function lineKeys(): array
    {
        return array_column($this->lines, 'key');
    }
}
