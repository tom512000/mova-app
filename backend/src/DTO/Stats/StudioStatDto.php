<?php

declare(strict_types=1);

namespace App\DTO\Stats;

/**
 * Deliberately narrower than PersonStatDto: no best and worst rating.
 *
 * Those two make sense for a person — a director's range is about them. A studio credited on
 * a hundred films has a best of 5 and a worst of 0.5 whatever it produced, so the pair says
 * nothing and would only fill the card with numbers nobody can act on.
 */
final readonly class StudioStatDto
{
    public function __construct(
        public string $studioId,
        public string $name,
        public int $movieCount,
        public ?float $averageRating,
    ) {
    }
}
