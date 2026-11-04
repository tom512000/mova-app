<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * A list export file once read: the list's own fields, then its films.
 */
final readonly class ListExport
{
    /**
     * @param array<int, array<string, string|null>> $rows films keyed by their 1-based data row
     */
    public function __construct(
        /** The list's boxd.it link — what identifies it from one export to the next. */
        public string $url,
        public string $name,
        public ?string $description,
        /** Comma-separated, as exported. */
        public string $tags,
        /** The day the list was created, as exported (AAAA-MM-JJ), or ''. */
        public string $date,
        public array $rows,
    ) {
    }
}
