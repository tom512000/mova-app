<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\ImportException;

/**
 * Reads a Letterboxd list export, which is not one CSV but two stacked in one file:
 *
 *     Letterboxd list export v7
 *     Date,Name,Tags,URL,Description            <- the list itself
 *     2026-10-11,Films français (Watchlist),,https://boxd.it/XSCwa,
 *                                               <- a blank line
 *     Position,Name,Year,URL,Description        <- then its films
 *     1,Anatomy of a Fall,2023,https://boxd.it/yuDE,
 *
 * CsvReader assumes one header over its rows and would read this as five-column rows keyed
 * on the signature line. Columns are still read by name rather than by position, for the
 * same reason CsvReader gives: the format is undocumented, and the "v7" in the signature
 * says it has already changed six times.
 */
final class ListCsvReader
{
    /** What the first line starts with, whatever the version number after it. */
    public const SIGNATURE = 'Letterboxd list export';

    /**
     * @throws ImportException when the file cannot be read or is not a list export
     */
    public function read(string $filepath): ListExport
    {
        $handle = @fopen($filepath, 'r');
        if (false === $handle) {
            throw new ImportException(sprintf('Impossible de lire le fichier "%s".', $filepath));
        }

        try {
            $signature = $this->nextNonBlank($handle);
            if (null === $signature || !self::isSignature((string) $signature[0])) {
                throw new ImportException('Ce fichier n\'est pas un export de liste Letterboxd.');
            }

            $listHeader = $this->nextNonBlank($handle);
            $listValues = null !== $listHeader ? $this->nextNonBlank($handle) : null;
            if (null === $listHeader || null === $listValues) {
                throw new ImportException('L\'en-tête de la liste est manquant.');
            }

            $list = $this->combine($listHeader, $listValues);

            $rows = [];
            // A list with no films stops after its own block: no second header at all.
            $filmHeader = $this->nextNonBlank($handle);
            if (null !== $filmHeader) {
                $rowNumber = 0;
                while (null !== ($values = $this->nextNonBlank($handle))) {
                    $rows[++$rowNumber] = $this->combine($filmHeader, $values);
                }
            }

            return new ListExport(
                url: trim((string) ($list['URL'] ?? '')),
                name: trim((string) ($list['Name'] ?? '')),
                description: $this->nullIfBlank($list['Description'] ?? null),
                tags: trim((string) ($list['Tags'] ?? '')),
                date: trim((string) ($list['Date'] ?? '')),
                rows: $rows,
            );
        } finally {
            fclose($handle);
        }
    }

    public static function isSignature(string $firstCell): bool
    {
        return str_starts_with(self::stripBom(trim($firstCell)), self::SIGNATURE);
    }

    /**
     * @param resource $handle
     *
     * @return list<string|null>|null the next line that holds anything, or null at the end
     */
    private function nextNonBlank($handle): ?array
    {
        while (false !== ($row = fgetcsv($handle))) {
            if (1 === \count($row) && (null === $row[0] || '' === trim($row[0]))) {
                continue;
            }

            // Same CRLF leftover CsvReader strips: the export is written with Windows line
            // endings, and fgetcsv on Alpine leaves the "\r" on the last field.
            return array_map(static fn (?string $v) => null !== $v ? rtrim($v, "\r\n") : null, $row);
        }

        return null;
    }

    /**
     * @param list<string|null> $header
     * @param list<string|null> $values
     *
     * @return array<string, string|null>
     */
    private function combine(array $header, array $values): array
    {
        $header = array_map(static fn (?string $column) => self::stripBom(trim((string) $column)), $header);
        $values = array_pad(\array_slice($values, 0, \count($header)), \count($header), null);

        return array_combine($header, $values);
    }

    private function nullIfBlank(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }

    private static function stripBom(string $value): string
    {
        return preg_replace('/^\x{FEFF}/u', '', $value) ?? $value;
    }
}
