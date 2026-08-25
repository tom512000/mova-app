<?php

declare(strict_types=1);

namespace App\DTO\Stats;

final readonly class WeekdayStatDto
{
    public function __construct(
        /** ISO-8601: 1 = Monday ... 7 = Sunday. */
        public int $weekday,
        public string $label,
        public int $watchCount,
        public ?float $averageRating,
    ) {
    }
}
