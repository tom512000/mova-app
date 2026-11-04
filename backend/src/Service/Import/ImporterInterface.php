<?php

declare(strict_types=1);

namespace App\Service\Import;

use App\Entity\Enum\ImportFileType;
use App\Entity\ImportBatch;

interface ImporterInterface
{
    public function getFileType(): ImportFileType;

    /**
     * @param string[] $header column names read from the first CSV line
     */
    public function supports(string $filename, array $header): bool;

    /**
     * How many rows import() will report on — the denominator of the progress bar.
     *
     * The importer's to answer rather than the CSV reader's, because not every export is one
     * header over its rows: a list file opens with a signature line and a block of list
     * metadata before its films, and counting those as rows would leave every list import
     * stuck short of 100 %.
     */
    public function countRows(string $filepath): int;

    /**
     * Imports every row of $filepath into $batch, on behalf of $batch's owning user,
     * updating its row counters and
     * attaching an ImportRowError for every row that could not be processed
     * (never letting a single bad row abort the whole batch).
     *
     * @return list<string> ids of Movie entities that were created or need re-checking for
     *                      TMDB enrichment
     */
    public function import(string $filepath, ImportBatch $batch): array;
}
