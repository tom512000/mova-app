<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class GenreStatDto
{
    public function __construct(
        public string $genreName,
        public int $movieCount,
        public int $watchCount,
        public ?float $averageRating,
        public int $totalWatchTimeMinutes,
    ) {
    }
}
