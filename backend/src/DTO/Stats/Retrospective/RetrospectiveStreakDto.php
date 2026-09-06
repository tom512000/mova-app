<?php

declare(strict_types=1);

namespace App\DTO\Stats\Retrospective;

/**
 * The longest run of consecutive days with at least one viewing.
 *
 * Carries its dates and not only its length: "douze jours" is a number, "du 3 au 14 août"
 * is a memory, and the whole point of a retrospective is the second one.
 */
final readonly class RetrospectiveStreakDto
{
    public function __construct(
        public int $days,
        public string $startDate,
        public string $endDate,
        public int $watchCount,
    ) {
    }
}
