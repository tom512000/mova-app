<?php

declare(strict_types=1);

namespace App\DTO\Game;

final readonly class ClueDto
{
    public function __construct(
        public string $label,
        public string $value,
    ) {
    }
}
