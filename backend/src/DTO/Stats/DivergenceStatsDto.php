<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class DivergenceStatsDto
{
    /**
     * @param DivergentWorkDto[] $above           rated further above the public score, widest gap first
     * @param DivergentWorkDto[] $below           rated further below it, widest gap first
     * @param int                $minimumVotes    the vote floor a work had to clear to be compared
     * @param int                $comparableCount how many works cleared it and carry a rating
     */
    public function __construct(
        public array $above,
        public array $below,
        public int $minimumVotes,
        public int $comparableCount,
    ) {
    }
}
