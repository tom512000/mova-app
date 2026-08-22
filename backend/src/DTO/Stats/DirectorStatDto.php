<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class DirectorStatDto
{
    public function __construct(
        public int $personId,
        public string $name,
        public int $movieCount,
        public ?float $averageRating,
        public ?float $bestRating,
        public ?float $worstRating,
    ) {
    }
}
