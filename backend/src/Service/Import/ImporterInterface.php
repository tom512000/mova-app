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
     * Imports every row of $filepath into $batch, updating its row counters and
     * attaching an ImportRowError for every row that could not be processed
     * (never letting a single bad row abort the whole batch).
     *
     * @return int[] ids of Movie entities that were created or need re-checking for TMDB enrichment
     */
    public function import(string $filepath, ImportBatch $batch): array;
}
