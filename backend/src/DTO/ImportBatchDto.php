<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\ImportFileType;
use App\Entity\Enum\ImportStatus;

final readonly class ImportBatchDto
{
    /**
     * @param ImportRowErrorDto[] $errorsSample
     */
    public function __construct(
        public string $id,
        public string $filename,
        public ImportFileType $fileType,
        public ImportStatus $status,
        public \DateTimeImmutable $startedAt,
        public ?\DateTimeImmutable $finishedAt,
        public int $rowsTotal,
        public int $rowsImported,
        public int $rowsSkipped,
        public int $rowsFailed,
        public float $progressPercent,
        public array $errorsSample,
    ) {
    }
}
