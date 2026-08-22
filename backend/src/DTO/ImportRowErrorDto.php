<?php

declare(strict_types=1);

namespace App\DTO;

final readonly class ImportRowErrorDto
{
    public function __construct(
        public int $rowNumber,
        public string $errorMessage,
    ) {
    }
}
