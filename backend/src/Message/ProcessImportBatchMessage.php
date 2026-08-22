<?php

declare(strict_types=1);

namespace App\Message;

final readonly class ProcessImportBatchMessage
{
    public function __construct(
        public int $importBatchId,
    ) {
    }
}
