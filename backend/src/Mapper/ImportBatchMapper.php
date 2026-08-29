<?php

declare(strict_types=1);

namespace App\Mapper;

use App\DTO\ImportBatchDto;
use App\DTO\ImportRowErrorDto;
use App\Entity\ImportBatch;

final class ImportBatchMapper
{
    private const ERROR_SAMPLE_LIMIT = 20;

    public function toDto(ImportBatch $batch): ImportBatchDto
    {
        $errorsSample = [];
        foreach ($batch->getRowErrors()->slice(0, self::ERROR_SAMPLE_LIMIT) as $rowError) {
            $errorsSample[] = new ImportRowErrorDto($rowError->getRowNumber(), $rowError->getErrorMessage());
        }

        $processed = $batch->getRowsImported() + $batch->getRowsSkipped() + $batch->getRowsFailed();
        $progressPercent = $batch->getRowsTotal() > 0
            ? round(min(100, $processed / $batch->getRowsTotal() * 100), 1)
            : 0.0;

        return new ImportBatchDto(
            id: (string) $batch->getId(),
            filename: $batch->getFilename(),
            fileType: $batch->getFileType(),
            status: $batch->getStatus(),
            startedAt: $batch->getStartedAt(),
            finishedAt: $batch->getFinishedAt(),
            rowsTotal: $batch->getRowsTotal(),
            rowsImported: $batch->getRowsImported(),
            rowsSkipped: $batch->getRowsSkipped(),
            rowsFailed: $batch->getRowsFailed(),
            progressPercent: $progressPercent,
            errorsSample: $errorsSample,
        );
    }
}
