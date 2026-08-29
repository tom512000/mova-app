<?php

declare(strict_types=1);

namespace App\DTO\Stats;

/**
 * Shared shape for any "most-watched person" ranking (directors, actors, ...) —
 * the aggregation only differs by which Credit role is filtered on.
 */
final readonly class PersonStatDto
{
    public function __construct(
        public string $personId,
        public string $name,
        public int $movieCount,
        public ?float $averageRating,
        public ?float $bestRating,
        public ?float $worstRating,
    ) {
    }
}
