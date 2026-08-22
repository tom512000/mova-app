<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Exception\ImportException;

/**
 * Reads a Letterboxd CSV as an iterable of associative rows keyed by header name,
 * so importers never depend on column position/order — the export format is not
 * officially documented and has been observed to vary slightly between accounts.
 */
final class CsvReader
{
    /**
     * @return iterable<int, array<string, string>> 1-indexed by data row number (header excluded)
     */
    public function readAssoc(string $filepath): iterable
    {
        $handle = @fopen($filepath, 'r');
        if (false === $handle) {
            throw new ImportException(sprintf('Impossible de lire le fichier "%s".', $filepath));
        }

        try {
            $header = fgetcsv($handle);
            if (false === $header) {
                return;
            }

            // Strip a UTF-8 BOM that Letterboxd sometimes prepends to the first column name.
            $header[0] = preg_replace('/^\x{FEFF}/u', '', (string) $header[0]) ?? $header[0];
            $header = array_map(static fn (?string $col) => trim((string) $col), $header);

            $rowNumber = 0;
            while (false !== ($row = fgetcsv($handle))) {
                ++$rowNumber;
                if (1 === \count($row) && null === $row[0]) {
                    continue; // blank trailing line
                }

                // The export uses CRLF line endings; fgetcsv() under Linux/Alpine leaves a
                // trailing "\r" on the last field of each row (confirmed against a real
                // export), which silently breaks date parsing and exact-value comparisons.
                $row = array_map(static fn (?string $v) => null !== $v ? rtrim($v, "\r\n") : $v, $row);

                $row = array_pad($row, \count($header), null);
                yield $rowNumber => array_combine($header, \array_slice($row, 0, \count($header)));
            }
        } finally {
            fclose($handle);
        }
    }

    public function countDataRows(string $filepath): int
    {
        $count = 0;
        foreach ($this->readAssoc($filepath) as $ignored) {
            ++$count;
        }

        return $count;
    }

    /**
     * @return string[] header column names, without reading the whole file
     */
    public function readHeader(string $filepath): array
    {
        $handle = @fopen($filepath, 'r');
        if (false === $handle) {
            throw new ImportException(sprintf('Impossible de lire le fichier "%s".', $filepath));
        }

        try {
            $header = fgetcsv($handle) ?: [];
            $header[0] = isset($header[0]) ? (preg_replace('/^\x{FEFF}/u', '', $header[0]) ?? $header[0]) : null;

            return array_map(static fn (?string $col) => trim((string) $col), $header);
        } finally {
            fclose($handle);
        }
    }
}
