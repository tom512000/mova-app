<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class RatingStatsDto
{
    /**
     * @param array<int, array{rating: float, count: int}> $distribution
     */
    public function __construct(
        public ?float $average,
        public ?float $median,
        public ?float $standardDeviation,
        public array $distribution,
    ) {
    }
}
