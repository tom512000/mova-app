<?php

declare(strict_types=1);

namespace App\DTO;

use App\Entity\Enum\ImportFileStatus;
use App\Entity\Enum\ImportFileType;

/**
 * One line of an upload's inventory: a file the archive contained, and what became of it.
 *
 * Columns and row count are read for every CSV, including the ones nothing imports. Those
 * are precisely the ones they are for: deciding whether a file is worth an importer starts
 * with knowing what is in it, without having to unzip the export by hand.
 */
final readonly class ImportFileReportDto
{
    /**
     * @param string[] $columns the header row, empty for anything that is not a CSV
     */
    public function __construct(
        public string $path,
        public ImportFileStatus $status,
        public ?ImportFileType $fileType,
        public ?string $reason,
        public array $columns,
        public ?int $rowCount,
    ) {
    }
}
