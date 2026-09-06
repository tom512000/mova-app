<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

/** The year before, for the only sentence that needs it: more, or less. */
final readonly class YearComparisonDto
{
    public function __construct(
        public int $year,
        public int $watchCount,
        public ?float $averageRating,
    ) {
    }
}
