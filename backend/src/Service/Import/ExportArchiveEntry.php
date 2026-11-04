<?php

declare(strict_types=1);

namespace App\Service\Import;

/**
 * One file found in an uploaded export, extracted or not.
 */
final readonly class ExportArchiveEntry
{
    private function __construct(
        /** Where the file sits inside the archive, e.g. `likes/films.csv`. */
        public string $path,
        /** On disk and ready for an importer. Null for a file left inside the archive. */
        public ?string $storedPath,
        /** What a CSV reader can open — the stored file, or a `zip://` URL. Null if not a CSV. */
        public ?string $readablePath,
        /** Why the file was left in the archive. Null when it was extracted. */
        public ?string $ignoredBecause,
    ) {
    }

    public static function extracted(string $path, string $storedPath): self
    {
        return new self($path, $storedPath, $storedPath, null);
    }

    public static function ignored(string $path, ?string $readablePath, string $because): self
    {
        return new self($path, null, $readablePath, $because);
    }
}
