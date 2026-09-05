<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class DecadeStatDto
{
    /**
     * @param int $decade the decade's first year: 1970 for the 1970s
     */
    public function __construct(
        public int $decade,
        public int $movieCount,
        public int $watchCount,
        public ?float $averageRating,
    ) {
    }
}
